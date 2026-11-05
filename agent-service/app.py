#!/usr/bin/env python3
"""Vision Law agent service — private HTTP front door for AgentRunner.

Stdlib-only HTTP layer (ThreadingHTTPServer) so the service boots on a
bare server with no pip installs; the Claude Agent SDK is imported
lazily by the runner and its absence degrades to a labeled
"agent not configured" error event, never a crash.

Endpoints (loopback only — Laravel is the only client):
  GET  /health  -> {"ok": true, "runner": ..., "configured": bool}
  POST /turn    -> NDJSON event stream (token/tool/done/error)

Every endpoint requires the shared internal token
(Authorization: Bearer <token> or X-Internal-Token), compared in
constant time. The token lives in the service env file, never in git.
"""

from __future__ import annotations

import asyncio
import hmac
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

from config import Settings
from runners import AgentRunner, make_runner

SETTINGS = Settings.from_env()
RUNNER: AgentRunner = make_runner(SETTINGS)


def token_ok(headers: Any) -> bool:
    expected = SETTINGS.internal_token
    if not expected:
        return False
    provided = None
    auth = headers.get("Authorization")
    if auth and auth.startswith("Bearer "):
        provided = auth[len("Bearer "):]
    if provided is None:
        provided = headers.get("X-Internal-Token")
    return provided is not None and hmac.compare_digest(provided, expected)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002
        pass  # keep journald clean; systemd captures real errors

    def _send_json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802 — BaseHTTPRequestHandler API
        if self.path != "/health":
            self._send_json(404, {"code": "not_found"})
            return
        if not token_ok(self.headers):
            self._send_json(403, {"code": "forbidden"})
            return
        self._send_json(
            200,
            {
                "ok": True,
                "runner": RUNNER.name,
                "configured": RUNNER.configured,
            },
        )

    def do_POST(self) -> None:  # noqa: N802 — BaseHTTPRequestHandler API
        if self.path != "/turn":
            self._send_json(404, {"code": "not_found"})
            return
        if not token_ok(self.headers):
            self._send_json(403, {"code": "forbidden"})
            return

        try:
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length) if length > 0 else b"{}"
            payload = json.loads(raw.decode("utf-8"))
        except Exception:  # noqa: BLE001
            self._send_json(422, {"code": "validation", "message": "Invalid JSON body."})
            return

        message = payload.get("message")
        if not isinstance(message, str) or not message.strip():
            self._send_json(
                422, {"code": "validation", "message": "message is required."}
            )
            return

        session_id = str(payload.get("session_id") or payload.get("conversation_id") or "")
        context = {
            "user_id": payload.get("user_id"),
            "org_id": payload.get("org_id"),
            "history": payload.get("history") or [],
            **(payload.get("context") or {}),
        }

        self.send_response(200)
        self.send_header("Content-Type", "application/x-ndjson")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Transfer-Encoding", "chunked")
        self.end_headers()

        # Stream events as the runner produces them (token by token),
        # driving the async generator from this sync handler thread.
        loop = asyncio.new_event_loop()
        try:
            agen = RUNNER.run_turn(session_id, message, context)
            while True:
                try:
                    event = loop.run_until_complete(agen.__anext__())
                except StopAsyncIteration:
                    break
                if self._write_line(event):
                    return  # client went away (stop/abort)
            try:
                self.wfile.write(b"0\r\n\r\n")
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                pass
        except Exception as exc:  # noqa: BLE001 — stream must end with an event
            self._write_line(
                {
                    "type": "error",
                    "code": "agent_turn_failed",
                    "message": f"The agent turn failed: {exc}",
                }
            )
            try:
                self.wfile.write(b"0\r\n\r\n")
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                pass
        finally:
            loop.close()

    def _write_line(self, event: dict[str, Any]) -> bool:
        """Write one NDJSON chunk; True means the client disconnected."""
        line = (json.dumps(event) + "\n").encode("utf-8")
        try:
            self.wfile.write(b"%x\r\n" % len(line))
            self.wfile.write(line)
            self.wfile.write(b"\r\n")
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            return True
        return False


def main() -> None:
    server = ThreadingHTTPServer((SETTINGS.host, SETTINGS.port), Handler)
    print(
        f"visionlaw-agent listening on {SETTINGS.host}:{SETTINGS.port} "
        f"runner={RUNNER.name} configured={RUNNER.configured}",
        flush=True,
    )
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()


if __name__ == "__main__":
    main()
