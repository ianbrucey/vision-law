#!/usr/bin/env python3
"""Vision Law deploy webhook listener.

Receives GitHub push webhooks on POST /deploy, verifies the
X-Hub-Signature-256 HMAC-SHA256 signature, and — for pushes to
refs/heads/main — waits for the CI check runs on the pushed SHA to
complete green (via the public GitHub API) before running
/opt/vision-law/deploy.sh.

Stdlib only. Logs to stdout with UTC timestamps (captured by journald).
"""

import hashlib
import hmac
import json
import subprocess
import threading
import time
import urllib.request
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SECRET_PATH = "/opt/vision-law/.webhook-secret"
DEPLOY_SCRIPT = "/opt/vision-law/deploy.sh"
REPO = "ianbrucey/vision-law"
LISTEN_HOST = "0.0.0.0"
LISTEN_PORT = 3120
CI_TIMEOUT_S = 15 * 60
CI_POLL_S = 20


def log(msg: str) -> None:
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    print(f"[{ts}] {msg}", flush=True)


def load_secret() -> bytes:
    with open(SECRET_PATH, "rb") as f:
        return f.read().strip()


SECRET = load_secret()
deploy_lock = threading.Lock()


def verify_signature(body: bytes, header: str | None) -> bool:
    if not header or not header.startswith("sha256="):
        return False
    expected = "sha256=" + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header)


def fetch_check_runs(sha: str) -> list:
    url = f"https://api.github.com/repos/{REPO}/commits/{sha}/check-runs?per_page=10"
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "vision-law-webhook",
            "Accept": "application/vnd.github+json",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    return data.get("check_runs", [])


def wait_for_ci_green(sha: str) -> bool:
    """Poll until every check run on sha is completed. True only if all green."""
    deadline = time.time() + CI_TIMEOUT_S
    runs: list = []
    while time.time() < deadline:
        try:
            runs = fetch_check_runs(sha)
        except Exception as e:  # network/API hiccup: keep polling
            log(f"ci-wait {sha[:8]}: API error: {e}")
            time.sleep(CI_POLL_S)
            continue
        pending = [r for r in runs if r.get("status") != "completed"]
        if runs and not pending:
            break
        log(f"ci-wait {sha[:8]}: {len(runs)} check run(s), {len(pending)} pending")
        time.sleep(CI_POLL_S)
    else:
        log(f"ci-wait {sha[:8]}: TIMEOUT after {CI_TIMEOUT_S // 60} min — skipping deploy")
        return False
    bad = [(r.get("name"), r.get("conclusion")) for r in runs
           if r.get("conclusion") != "success"]
    if bad:
        log(f"ci-wait {sha[:8]}: NOT GREEN {bad} — skipping deploy")
        return False
    log(f"ci-wait {sha[:8]}: all {len(runs)} check run(s) green")
    return True


def run_deploy(sha: str) -> None:
    log(f"deploy {sha[:8]}: starting {DEPLOY_SCRIPT}")
    try:
        proc = subprocess.run(
            [DEPLOY_SCRIPT], capture_output=True, text=True, timeout=600
        )
        log(f"deploy {sha[:8]}: finished rc={proc.returncode}")
        out = (proc.stdout or "").strip().splitlines()
        for line in out[-5:]:
            log(f"deploy {sha[:8]} stdout: {line}")
        if proc.returncode != 0:
            err = (proc.stderr or "").strip().splitlines()
            for line in err[-5:]:
                log(f"deploy {sha[:8]} stderr: {line}")
    except subprocess.TimeoutExpired:
        log(f"deploy {sha[:8]}: TIMEOUT after 600s")
    except Exception as e:
        log(f"deploy {sha[:8]}: ERROR {e}")


def handle_push_async(sha: str) -> None:
    log(f"push {sha[:8]}: accepted, waiting for CI")
    if not wait_for_ci_green(sha):
        return
    log(f"push {sha[:8]}: acquiring deploy lock")
    with deploy_lock:
        run_deploy(sha)


class Handler(BaseHTTPRequestHandler):
    server_version = "visionlaw-webhook/1.0"

    def _send(self, code: int, body: str = "") -> None:
        data = body.encode()
        self.send_response(code)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        if data:
            self.wfile.write(data)

    def _not_found(self) -> None:
        log(f"404 {self.command} {self.path}")
        self._send(404, "not found\n")

    def do_POST(self) -> None:
        if self.path != "/deploy":
            self._not_found()
            return
        try:
            length = int(self.headers.get("Content-Length", 0) or 0)
        except ValueError:
            length = 0
        body = self.rfile.read(length)
        sig = self.headers.get("X-Hub-Signature-256")
        if not verify_signature(body, sig):
            log("401 signature mismatch")
            self._send(401, "bad signature\n")
            return
        try:
            payload = json.loads(body.decode("utf-8"))
        except Exception:
            log("400 bad JSON")
            self._send(400, "bad json\n")
            return
        event = self.headers.get("X-GitHub-Event", "")
        ref = payload.get("ref", "")
        sha = payload.get("after") or ""
        if (
            event != "push"
            or ref != "refs/heads/main"
            or not sha
            or set(sha) == {"0"}
        ):
            log(f"202 ignored event={event!r} ref={ref!r}")
            self._send(202, "ignored\n")
            return
        log(f"202 accepted push {sha[:8]} on {ref}")
        self._send(202, "deploy queued\n")
        threading.Thread(target=handle_push_async, args=(sha,), daemon=True).start()

    def do_GET(self) -> None:
        self._not_found()

    def do_PUT(self) -> None:
        self._not_found()

    def do_DELETE(self) -> None:
        self._not_found()

    def do_PATCH(self) -> None:
        self._not_found()

    def do_HEAD(self) -> None:
        self._not_found()

    def log_message(self, *args) -> None:
        pass  # we log ourselves, with timestamps


def main() -> None:
    srv = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler)
    log(f"listening on {LISTEN_HOST}:{LISTEN_PORT}")
    srv.serve_forever()


if __name__ == "__main__":
    main()
