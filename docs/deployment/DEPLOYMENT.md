# Vision Law — Deployment

## Webhook push-to-deploy (proprietary)

No GitHub Actions deploy job, no SSH keys in CI secrets. GitHub sends a
signed webhook on every push; a small listener on the dev server verifies
the signature, waits for CI to go green, then deploys.

```
git push origin main
  → GitHub Actions: ci job (Pint → PHPStan → Pest on Postgres)
  → GitHub webhook: POST http://172.235.37.44:3120/deploy
        (push event, HMAC-SHA256 signed)
  → visionlaw-webhook.service (/opt/vision-law/webhook/server.py, stdlib only)
      1. verify X-Hub-Signature-256 (constant-time compare) → 401 on mismatch
      2. accept only push events on refs/heads/main → 202 ignored otherwise
      3. poll the public GitHub check-runs API for the head SHA
         (every 20s, up to 15 min) until every check run is completed
      4. deploy ONLY if every check run concluded `success`
      5. run /opt/vision-law/deploy.sh (serialized — deploys never overlap):
             git pull --ff-only
             composer install --no-dev --optimize-autoloader
             npm ci && npm run build
             php artisan migrate --force
             php artisan optimize
             systemctl restart visionlaw-web
```

### Why this design

- **No secret-paste fragility.** The previous approach needed a multiline
  SSH private key pasted into a GitHub Actions secret; it failed 3 times
  (SSH died in ~1s before any server contact, CI green every time). The
  webhook uses a single-line token (paste-safe) as the HMAC secret.
- **Zero Actions minutes for deploys.** CI still runs on Actions (cheap);
  the deploy step itself costs nothing.
- **CI gate preserved.** The listener polls the public check-runs API for
  the pushed SHA and deploys only when every check run is green — the same
  guarantee `needs: ci` gave, enforced server-side.

### Webhook setup (GitHub)

Repo → Settings → Webhooks → Add webhook:

- **Payload URL:** `http://172.235.37.44:3120/deploy`
- **Content type:** `application/json`
- **Secret:** the token (single line, no whitespace)
- **Which events:** "Just the push event"
- **Active:** checked

No Actions secrets are needed for deploys. The old `VISIONLAW_DEPLOY_KEY`
secret can be deleted.

### Components

| Path | What |
|---|---|
| `/opt/vision-law/webhook/server.py` | The listener (Python 3, stdlib only: `http.server`, `hmac`, `urllib`) |
| `/opt/vision-law/.webhook-secret` | Shared HMAC token (mode 600, root-only, gitignored) |
| `/etc/systemd/system/visionlaw-webhook.service` | systemd unit (`Restart=always`) |
| `/opt/vision-law/deploy.sh` | The deploy script (unchanged from the Actions era) |

### Manual deploy

```bash
/opt/vision-law/deploy.sh
```

### Logs

```bash
journalctl -u visionlaw-webhook -f   # decisions: received / ignored / CI wait / deploy rc
journalctl -u visionlaw-web -f       # app logs
```

### Troubleshooting

- **Push didn't deploy** → check the webhook log first:
  `journalctl -u visionlaw-webhook --since "30 min ago"`.
  - `202 ignored` → event wasn't a main-branch push (PRs, other branches).
  - `401 signature mismatch` → the webhook secret on GitHub doesn't match
    `/opt/vision-law/.webhook-secret`.
  - `NOT GREEN` → CI failed; fix CI, the next green push deploys.
  - `TIMEOUT` → CI took longer than 15 min; rerun or push again.
- **Endpoint self-test** (never triggers a deploy):
  wrong signature → expect `401`; valid signature + non-push event → `202 ignored`.
- The listener binds `0.0.0.0:3120` with no TLS. The HMAC signature is the
  authentication — never expose the token. GitHub signs every delivery;
  anything unsigned gets a 401.
