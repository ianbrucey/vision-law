# 001 — Foundation: authentication, RBAC, and audit logging — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-06
**Brief:** `00-brief.md` (DRAFT — pending Ian's approval)

> Archaeology was performed against the live scaffold at /opt/vision-law
> (Laravel 13.35.0, PHP 8.4.26) on 2026-10-06. Baseline: `php artisan test`
> 2 passed · `vendor/bin/pint --test` clean (26 files) ·
> `vendor/bin/phpstan analyse` no errors · `npm run build` not yet run
> (no frontend assets customized). No auth packages installed.

---

## Findings

| Thing (code, migration, test, doc, config) | Location | Verdict | Constraint / note |
|---|---|---|---|
| Laravel 13.35.0 scaffold (routes, controllers, User model stub, migrations) | `/opt/vision-law` | REUSE | Standard scaffold; User model will be EXTENDed (see below) |
| `App\Models\User` (scaffold default) | `app/Models/User.php` | EXTEND | Add UUID PK, `org_id`, Fortify 2FA traits, spatie `HasRoles`; keep `Notifiable` |
| Default `users` migration | `database/migrations/0001_01_01_000000_create_users_table.php` | EXTEND | Will be replaced by this feature's own migration set (scaffold migration edited before any merge — allowed: nothing has merged yet) |
| `config/hashing.php` | `config/hashing.php` | EXTEND | Switch driver to `argon2id` with PLT-02 parameters (memory 65536, time 3, threads 1); weaker params in `phpunit.xml` env |
| `config/session.php` | `config/session.php` | EXTEND | Driver `database` for PLT-08 session listing; add `sessions` table migration |
| `routes/web.php` | `routes/web.php` | EXTEND | Auth + admin + matter-grant routes added here; guest exceptions listed explicitly |
| `laravel/fortify` | — (not installed) | NEW | Headless auth backend: login, 2FA, password reset, email verification, password confirmation. Chosen over Breeze (see decisions.md 001-D01) |
| `spatie/laravel-permission` | — (not installed) | NEW | Org-level RBAC (roles/permissions). Migrations published and converted to UUID morph columns |
| `organizations`, `teams`, `invitations`, `matter_grants`, `audit_events`, `matters` (minimal) tables | — | NEW | Per `02-schema-delta.md` |
| `AuditLogger` service | — | NEW | Sole writer of `audit_events`; computes hash chain inside a transaction |
| `RequireMatterAccess` middleware + `MatterPolicy` | — | NEW | Central `authorize(user, action, object)`; 404-not-403 semantics |
| `tests/Architecture` suite | — | NEW | Doors: no Storage in controllers; audit only via AuditLogger; auth-by-default routes |
| `DocumentStore` | — | N/A | Referenced by drafting architecture; does not exist yet and is NOT built here |
| Old vision Python app (`/opt/vision`) | `/opt/vision` | CONFLICT | Reference implementation only — do not import, port, or depend on it |

## Architectural doors this feature must use

- All matter access goes through the centralized authorization check (`AccessControl::authorize` / `RequireMatterAccess`) **before** any data access.
- Audit events are written **only** through `AuditLogger` — no direct `audit_events` inserts anywhere else (enforced by architecture test).
- Controllers never touch the Storage facade (enforced by architecture test; no file handling in this feature anyway).
- Routes are authenticated by default; the guest route list (login, register, reset, verify, invitation accept, 2FA challenge) is explicit and minimal.
- Existence-leak rule: cross-org or no-grant access to a resource returns **404**; insufficient role on a visible resource returns **403**.

## Files to touch

| File | Action (create/edit) | Owner (worker) | Reason |
|---|---|---|---|
| `composer.json` | edit | orchestrator | Add `laravel/fortify`, `spatie/laravel-permission` |
| `config/fortify.php` | create | T-01 | Fortify config (features: registration, reset, verify, 2FA, password confirmation) |
| `config/permission.php` | create (published) | T-01 | Spatie config; UUID-compatible |
| `config/hashing.php` | edit | T-01 | argon2id, PLT-02 params; test-env override in `phpunit.xml` |
| `config/session.php` | edit | T-01 | `database` driver |
| `database/migrations/20*_*.php` | create | T-02 | All tables per schema delta (ordered) |
| `app/Models/*.php` | create/edit | T-03 | Organization, User (extend), Team, Invitation, Matter (minimal), MatterGrant, AuditEvent |
| `app/Services/AuditLogger.php` | create | T-03 | Sole audit writer, hash chain |
| `app/Services/AccessControl.php` | create | T-04 | Central authorize() + effective-permission computation |
| `app/Http/Middleware/RequireMatterAccess.php` | create | T-04 | Route-level matter gate |
| `app/Policies/MatterPolicy.php` (+ UserPolicy) | create | T-04 | Policy layer for grants/admin |
| `app/Http/Controllers/Auth/*` | create | T-05 | Fortify action classes + session management controllers |
| `app/Http/Controllers/Admin/*` | create | T-05 | User/team/invitation/audit-viewer controllers |
| `resources/views/auth/*`, `resources/views/admin/*` | create | T-05 | Blade views per UI standards (provisional) |
| `routes/web.php` | edit | orchestrator | Route registration (shared file) |
| `tests/Feature/*`, `tests/Architecture/*` | create | per ticket | Verdict tests + doors |
| `database/seeders/*`, `tests/Helpers/*` | create | T-03 | Fixture loader (single definition per protocol) |

## Protected files

- `docs/*` — system docs; changed only by recorded decision, never by feature tickets.
- `specs/001-*/00-brief.md` verdicts — builders may challenge before approval, never rewrite unilaterally.
- Migrations once merged — new change = new migration.
- `.github/workflows/tests.yml` — orchestrator-owned (CI shape).

## Baseline

- Tests: `php artisan test` — 2 passed, 0 failed (2026-10-06).
- Architecture tests: none yet (created by this feature).
- Pint: `vendor/bin/pint --test` — clean, 26 files.
- PHPStan: `vendor/bin/phpstan analyse` — no errors (level 7 + Larastan).
- Asset build: not run — no frontend changes in scaffold; first `npm run build` happens in T-05.
- Framework/package versions: `laravel/framework` 13.35.0 (composer.lock); `laravel/fortify` and `spatie/laravel-permission` versions to be pinned at T-01 after verifying Laravel 13 support.
- Baseline debt in scope: none. The scaffold is clean; no cleanup authorized.
