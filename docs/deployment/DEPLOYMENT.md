# Vision Law — Deployment

## Push-to-deploy

Every push to `main` runs CI (Pint → PHPStan → Pest on Postgres). When CI
passes, the `deploy` job SSHes to the dev server and runs the deploy script.

```
git push origin main
  → GitHub Actions: ci job (tests)
  → GitHub Actions: deploy job (needs: ci, concurrency: deploy-visionlaw)
      → ssh root@172.235.37.44  (forced-command key)
      → /opt/vision-law/deploy.sh
          git pull --ff-only
          composer install --no-dev --optimize-autoloader
          npm ci && npm run build
          php artisan migrate --force
          php artisan optimize
          systemctl restart visionlaw-web
      → smoke check: curl http://172.235.37.44:3100/up
```

`concurrency: deploy-visionlaw` serializes deploys — a second push waits for
the first deploy to finish instead of racing it.

## The forced-command key

The deploy key in GitHub **cannot open a shell**. On the server,
`/root/.ssh/authorized_keys` pins it to a single command:

```
command="/opt/vision-law/deploy.sh",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty <key>
```

Any SSH session authenticating with this key runs `deploy.sh` and nothing
else — even if the CI job were compromised, the key cannot be used to run
arbitrary commands, open tunnels, or read other apps' data.

The keypair lives at `/root/.ssh/id_visionlaw_deploy` (`visionlaw-deploy`
label). The public half is in `authorized_keys`; the private half must be
stored as the GitHub secret below.

## GitHub secret (required — added by Ian)

The `deploy` job reads **`VISIONLAW_DEPLOY_KEY`**. Until it exists, pushes to
`main` will show CI green and the deploy job failing on the missing secret —
that failure is harmless and expected.

Add it at: GitHub → ianbrucey/vision-law → **Settings → Secrets and
variables → Actions → New repository secret**

- Name: `VISIONLAW_DEPLOY_KEY`
- Value: the full contents of the private key (including the
  `-----BEGIN/END OPENSSH PRIVATE KEY-----` lines)

## Manual deploy

On the dev server, as root:

```bash
/opt/vision-law/deploy.sh
```

This is exactly what CI runs. Useful for deploys you want to watch, or for
recovering a box without involving GitHub.

## Service layout

| Piece | Location |
|---|---|
| App | `/opt/vision-law` |
| Deploy script | `/opt/vision-law/deploy.sh` |
| Systemd unit (live) | `/etc/systemd/system/visionlaw-web.service` |
| Systemd unit (repo copy) | `deploy/visionlaw-web.service` |
| Web | `php artisan serve` on `0.0.0.0:3100` |
| Database | `visionlaw-pg` container, Postgres 18 + pgvector, `127.0.0.1:5435` |

Edit the unit via the repo copy, then copy it to `/etc/systemd/system/`
and `systemctl daemon-reload` — `/etc` is the live copy, the repo is the
record.

## Logs and status

```bash
systemctl status visionlaw-web
journalctl -u visionlaw-web -f        # live logs
journalctl -u visionlaw-web --since today
```

## Upgrade path (later, when traffic warrants)

- **Caddy + domain**: add a site block reverse-proxying `127.0.0.1:3100`
  (same pattern as the other apps on this box) instead of raw-port access.
- **Octane / FrankenPHP**: replace `php artisan serve` with a long-lived
  worker when request volume or latency demands it. The systemd unit is the
  only piece that changes; `deploy.sh` stays the same.
