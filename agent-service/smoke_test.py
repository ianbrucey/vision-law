#!/usr/bin/env python3
"""Smoke test for the agent service — stdlib only.

Boots the service on an ephemeral loopback port with NO provider key,
then asserts:
  1. GET /health answers and reports configured=false, and
  2. POST /turn returns the clean, labeled agent_not_configured error
     event (the path Ian hits until his API key is supplied), and
  3. requests without the internal token are refused.

Usage: python3 smoke_test.py
Exit code 0 = pass.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
PORT = 18199
TOKEN = "smoke-test-token"
BASE = f"http://127.0.0.1:{PORT}"


def request(path: str, *, method: str = "GET", body: dict | None = None,
            token: str | None = TOKEN) -> tuple[int, str]:
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(f"{BASE}{path}", data=data, method=method)
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return resp.status, resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8")


def main() -> int:
    env = dict(os.environ)
    env.update(
        {
            "AGENT_PORT": str(PORT),
            "AGENT_INTERNAL_TOKEN": TOKEN,
            "AGENT_RUNNER": "claude",
            # Deliberately no provider key: the unconfigured path.
            "ANTHROPIC_AUTH_TOKEN": "",
            "ANTHROPIC_API_KEY": "",
        }
    )
    env.pop("ANTHROPIC_AUTH_TOKEN", None)
    env.pop("ANTHROPIC_API_KEY", None)

    proc = subprocess.Popen(
        [sys.executable, os.path.join(HERE, "app.py")],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    try:
        deadline = time.time() + 15
        health = None
        while time.time() < deadline:
            if proc.poll() is not None:
                out = proc.stdout.read() if proc.stdout else ""
                print(f"FAIL: service exited early:\n{out}")
                return 1
            try:
                status, body = request("/health")
                if status == 200:
                    health = json.loads(body)
                    break
            except Exception:  # noqa: BLE001 — still booting
                pass
            time.sleep(0.3)

        if health is None:
            print("FAIL: /health never answered 200")
            return 1
        assert health.get("ok") is True, f"health not ok: {health}"
        assert health.get("configured") is False, f"expected configured=false: {health}"
        print(f"PASS health: {health}")

        status, _ = request("/health", token=None)
        assert status == 403, f"expected 403 without token, got {status}"
        print("PASS unauthenticated health refused (403)")

        status, body = request(
            "/turn",
            method="POST",
            body={"session_id": "smoke", "message": "hello", "user_id": "u1"},
        )
        assert status == 200, f"turn status {status}: {body}"
        events = [json.loads(line) for line in body.splitlines() if line.strip()]
        assert events, "turn returned no events"
        last = events[-1]
        assert last.get("type") == "error", f"expected error event, got {events}"
        assert last.get("code") == "agent_not_configured", f"unexpected code: {last}"
        assert "not configured" in str(last.get("message", "")).lower(), last
        print(f"PASS unconfigured turn: {last}")

        print("SMOKE OK")
        return 0
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


if __name__ == "__main__":
    raise SystemExit(main())
