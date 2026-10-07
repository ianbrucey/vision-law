#!/usr/bin/env python3
"""Vision Law deploy trigger listener.

Two triggers feed one deploy path:

1. GitHub push webhook on POST /deploy (HMAC-SHA256 verified). Optional —
   works if a webhook is registered on the repo.

2. Poller (primary): every 90s the listener asks the public GitHub API for
   the HEAD SHA of main via a conditional request (If-None-Match / ETag, so
   an unchanged poll costs no rate limit). A new SHA is treated exactly like
   a webhook push. Zero GitHub-side configuration required.

Both triggers share handle_new_sha(): the SHA is claimed exactly once
(persisted in .poller-last-sha, so restarts never redeploy the same commit),
CI check runs on the SHA must all be green, then /opt/vision-law/deploy.sh
runs under a deploy lock.

Stdlib only. Logs to stdout with UTC timestamps (captured by journald).
"""

import hashlib
import hmac
import json
import subprocess
import threading
import time
import urllib.error
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

POLL_INTERVAL_S = 90
POLL_ETAG_PATH = "/opt/vision-law/webhook/.poller-etag"
POLL_LAST_SHA_PATH = "/opt/vision-law/webhook/.poller-last-sha"
POLL_USER_AGENT = "visionlaw-deploy-poller"


def log(msg: str) -> None:
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    print(f"[{ts}] {msg}", flush=True)


def load_secret() -> bytes:
    with open(SECRET_PATH, "rb") as f:
        return f.read().strip()


SECRET = load_secret()
deploy_lock = threading.Lock()
last_sha_lock = threading.Lock()


def verify_signature(body: bytes, header: str | None) -> bool:
    if not header or not header.startswith("sha256="):
        return False
    expected = "sha256=" + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header)


def read_last_handled_sha() -> str | None:
    try:
        with open(POLL_LAST_SHA_PATH) as f:
            return f.read().strip() or None
    except FileNotFoundError:
        return None


def write_last_handled_sha(sha: str) -> None:
    with open(POLL_LAST_SHA_PATH, "w") as f:
        f.write(sha + "\n")


def claim_sha(sha: str) -> bool:
    """Atomically claim a SHA for the deploy path. False if already handled."""
    with last_sha_lock:
        if read_last_handled_sha() == sha:
            return False
        write_last_handled_sha(sha)
        return True


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


def handle_new_sha(sha: str, source: str) -> None:
    """Shared trigger path for webhook pushes and poller discoveries.

    The SHA is claimed exactly once (persisted), so a webhook and a poller
    hit for the same commit can never double-deploy, and a service restart
    never redeploys what was already handled.
    """
    if not claim_sha(sha):
        log(f"{source} {sha[:8]}: already handled, skipping")
        return
    log(f"{source} {sha[:8]}: accepted, waiting for CI")
    if not wait_for_ci_green(sha):
        return
    log(f"{source} {sha[:8]}: acquiring deploy lock")
    with deploy_lock:
        run_deploy(sha)


def fetch_main_sha() -> str | None:
    """Return the current HEAD SHA of main, or None on a 304 (unchanged).

    Sends If-None-Match from the persisted ETag; persists the new ETag on
    a 200. A 304 costs no API rate limit.
    """
    etag: str | None = None
    try:
        with open(POLL_ETAG_PATH) as f:
            etag = f.read().strip() or None
    except FileNotFoundError:
        pass
    headers = {
        "User-Agent": POLL_USER_AGENT,
        "Accept": "application/vnd.github+json",
    }
    if etag:
        headers["If-None-Match"] = etag
    req = urllib.request.Request(
        f"https://api.github.com/repos/{REPO}/commits/main", headers=headers
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            new_etag = resp.headers.get("ETag")
            if new_etag:
                with open(POLL_ETAG_PATH, "w") as f:
                    f.write(new_etag)
            data = json.loads(resp.read().decode("utf-8"))
            return data.get("sha")
    except urllib.error.HTTPError as e:
        if e.code == 304:
            return None  # unchanged — quiet by design
        raise


def init_poller_state() -> None:
    """On boot: adopt the current main SHA as already-handled (no deploy).

    A persisted record from a previous run is kept as-is, so a restart
    never redeploys. Only future SHAs trigger the deploy path.
    """
    existing = read_last_handled_sha()
    if existing:
        log(f"poller: resuming, last handled {existing[:8]}")
        return
    try:
        sha = fetch_main_sha()
    except Exception as e:
        log(f"poller: boot init API error: {e} — will adopt on first poll")
        return
    if sha:
        write_last_handled_sha(sha)
        log(f"poller: initialized, current main {sha[:8]} (no deploy)")


def poller_loop() -> None:
    log(f"poller: started, interval {POLL_INTERVAL_S}s")
    while True:
        try:
            sha = fetch_main_sha()
            if sha is not None:
                # New SHA on main (or first fetch): run the shared path.
                # claim_sha dedupes, so this only ever deploys unseen SHAs.
                log(f"poller: main moved to {sha[:8]}")
                handle_new_sha(sha, "poller")
            # 304 (None): nothing changed — stay quiet.
        except Exception as e:
            log(f"poller: error: {e}")
        time.sleep(POLL_INTERVAL_S)


class Handler(BaseHTTPRequestHandler):
    server_version = "visionlaw-webhook/1.1"

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
        threading.Thread(target=handle_new_sha, args=(sha, "webhook"), daemon=True).start()

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
    init_poller_state()
    poller = threading.Thread(target=poller_loop, daemon=True)
    poller.start()
    srv = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler)
    log(f"listening on {LISTEN_HOST}:{LISTEN_PORT}")
    srv.serve_forever()


if __name__ == "__main__":
    main()
