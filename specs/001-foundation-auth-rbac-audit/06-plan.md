# 001 — Foundation: authentication, RBAC, and audit logging — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-06
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out tickets. A ticket is complete only when its own verdicts
> AND the standing gates (architecture suite, Pint, PHPStan) are green. Do not
> start the next ticket on a red gate. Parallel workers get disjoint file
> ownership (see `01-archaeology.md`).

---

## Ticket 1 — Packages, hashing, and session config

**Files:** `composer.json`, `config/fortify.php` (create), `config/permission.php` (published), `config/hashing.php` (edit), `config/session.php` (edit), `phpunit.xml` (edit)
**Inputs:** `01-archaeology.md` baseline; `decisions.md` 001-D01/001-D02
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `docs/*`, `specs/001-*/00-brief.md` verdicts, `.github/workflows/tests.yml`

### Work
- [ ] `composer require laravel/fortify spatie/laravel-permission` — verify Laravel 13 support in the resolved versions; record exact versions in `decisions.md`. If either lacks Laravel 13 support, STOP and report (do not hand-roll without approval).
- [ ] Publish spatie config + migrations (migrations customized in T-02).
- [ ] `config/hashing.php`: driver `argon2`; `argon2id` memory 65536, time 3, threads 1. `phpunit.xml`: weaker test-env params (documented in the file comment).
- [ ] `config/session.php`: driver `database`.
- [ ] Fortify config: enable registration, password reset, email verification, 2FA, password confirmation; disable Fortify's default views (custom Blade per UI standards).

### Acceptance criteria
- [ ] `composer validate` passes; `php artisan test` still 2 passed (no regressions)
- [ ] `vendor/bin/pint --test` clean; `vendor/bin/phpstan analyse` no errors
- [ ] Resolved package versions recorded in `decisions.md`

---

## Ticket 2 — Migrations for all tables

**Files:** `database/migrations/20*_*.php` (10 migrations, ordered per `02-schema-delta.md`)
**Inputs:** `02-schema-delta.md` (all tables, migration order)
**Dependencies:** Ticket 1
**Forbidden edits:** scaffold migrations from other features (none exist yet); `docs/*`

### Work
- [ ] Write migrations 1–10 in order: organizations → users → password_reset_tokens → sessions → invitations → teams (+pivot) → spatie permission tables (published, UUID morphs) → matters (minimal stub) → matter_grants → audit_events (ending with the REVOKE).
- [ ] `php artisan migrate:fresh` on a scratch Postgres; verify REVOKE took effect (attempted UPDATE on audit_events as app role fails).

### Acceptance criteria
- [ ] `migrate:fresh` + `migrate:rollback` + `migrate` cycle clean
- [ ] Schema matches `02-schema-delta.md` column-for-column (review checklist)
- [ ] Pint + PHPStan green

---

## Ticket 3 — Models, AuditLogger, and append-only enforcement

**Files:** `app/Models/*.php`, `app/Services/AuditLogger.php`, `database/seeders/*`, `tests/Helpers/FixtureLoader.php`, `tests/Feature/AuditLogTest.php`
**Inputs:** `02-schema-delta.md`, `04-fixtures.json` (canonical — loader reads THIS file; no hand-written duplicate)
**Dependencies:** Ticket 2
**Forbidden edits:** migrations from T-02 (new change = new migration)

### Work
- [ ] Models: Organization, User (UUID PK, HasRoles, 2FA), Team, Invitation, Matter (minimal), MatterGrant, AuditEvent (no `updated_at`; `updating`/`deleting` model events throw).
- [ ] `AuditLogger::log()` — sole writer; validates payload against privileged-key blocklist; computes hash chain in-transaction under per-org advisory lock.
- [ ] `FixtureLoader` reads `04-fixtures.json`; seeders for local dev.
- [ ] Seed the five-role permission matrix (single source of truth per `03-contract.md`).

### Acceptance criteria
- [ ] `test_audit_log_rejects_update_and_delete` (C-12 core) — direct UPDATE/DELETE attempts fail at DB and model level
- [ ] `test_audit_payload_rejects_privileged_keys` — logger throws on password/secret/token keys
- [ ] `test_fixture_loader_loads_all_adversarial_cases` — every `adv_*` case materializes
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 4 — Authentication flows

**Files:** `app/Http/Controllers/Auth/*` (Fortify action classes), `resources/views/auth/*`, `routes/web.php` (auth section)
**Inputs:** `03-contract.md` §Routes (guest list), §Requests & validation, §Error catalog; `05-ui.md` screens 1–4
**Dependencies:** Ticket 3
**Forbidden edits:** `app/Models/*` (extend only via new methods if needed — prefer not)

### Work
- [ ] Fortify wiring: registration (invitation-token-or-open per org settings), login with brute-force counting (5 → 15-min lockout, exponential backoff), password reset (1 h single-use token, session revocation, generic responses), email verification gate, 2FA challenge + enrollment (confirm-with-code, backup codes, password re-auth to disable).
- [ ] Login throttling keyed per account AND per IP; lockout events logged.
- [ ] Blade views for screens 1–4 per UI standards (provisional).

### Acceptance criteria
- [ ] `test_registration_requires_email_verification_before_login` (C-01)
- [ ] `test_weak_and_breached_passwords_rejected` (C-02)
- [ ] `test_brute_force_lockout_after_five_failures` (C-03)
- [ ] `test_password_reset_revokes_sessions_and_uses_generic_responses` (C-04)
- [ ] `test_mfa_challenge_blocks_login_without_valid_code` (C-06)
- [ ] Leak sentinels: no password hashes / tokens / secrets in any response, log, or mail for these flows
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 5 — RBAC, teams, invitations, session management

**Files:** `app/Http/Controllers/Admin/*`, `app/Services/InvitationService.php`, `app/Http/Controllers/SessionController.php`, `resources/views/admin/*`, `resources/views/sessions/*`
**Inputs:** `03-contract.md` §Permission matrix, §Routes (admin), `04-fixtures.json` users/teams
**Dependencies:** Ticket 4
**Forbidden edits:** auth controllers from T-04

### Work
- [ ] Admin user CRUD: invite (email + role + optional matter scope), role assignment, deactivation; team CRUD + membership; invitation list + revoke + nightly purge command.
- [ ] Invitation accept flow: email-bound (rejects wrong signed-in user with generic message).
- [ ] Session list, per-session revoke, revoke-all (password.confirm), concurrent-session limit (default 5), 12 h absolute lifetime for privileged roles.
- [ ] Admin Blade views per `05-ui.md` screens 6–8.

### Acceptance criteria
- [ ] `test_invitation_token_rejected_for_different_signed_in_user` (C-05)
- [ ] `test_session_revoke_and_concurrent_session_limit` (C-07)
- [ ] `test_outside_counsel_cannot_list_org_users` (C-10 core)
- [ ] `test_team_grant_gives_matter_access` (C-09) — needs T-06's grant evaluation; if T-06 isn't done, this verdict moves to T-06 (noted in decisions.md)
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 6 — Matter grants and the authorization proving ground

**Files:** `app/Services/AccessControl.php`, `app/Http/Middleware/RequireMatterAccess.php`, `app/Policies/MatterPolicy.php`, `app/Http/Controllers/MatterController.php` (minimal `show`), `app/Http/Controllers/MatterGrantController.php`, `resources/views/matters/grants/*`
**Inputs:** `03-contract.md` §Domain service methods, §Routes (matter), §Role-visible data; `04-fixtures.json` matters/grants
**Dependencies:** Ticket 5
**Forbidden edits:** `02-schema-delta.md` matters stub (EXTEND only via new migration — not in this feature)

### Work
- [ ] `AccessControl::authorize()` + `effectiveMatterRole()` — most-permissive of org default / team grants / direct grants; `outside_counsel` default-deny; expired grants deny automatically.
- [ ] `RequireMatterAccess` middleware: 404 when no grant or cross-org; 403 when role insufficient on a visible matter. Evaluated BEFORE data access.
- [ ] Minimal `GET /matters/{matter}` (title/status only — the authorization proving ground; full matter CRUD is a later feature).
- [ ] Grant CRUD: create (matter_admin+), revoke (sets `expires_at`, never deletes), cannot-remove-last-owner guard.

### Acceptance criteria
- [ ] `test_cross_org_matter_access_returns_404` (C-08)
- [ ] `test_expired_matter_grant_denies_access` (C-11)
- [ ] Every `adv_*` case in `04-fixtures.json` has a passing test asserting its exact HTTP code + audit event
- [ ] Leak sentinels: other matters' titles/numbers absent from all responses for denied actors
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 7 — Audit viewer, architecture doors, evidence & freeze

**Files:** `app/Http/Controllers/Admin/AuditEventController.php`, `resources/views/admin/audit-events/*`, `tests/Architecture/*`, `07-evidence/`, `decisions.md`
**Inputs:** `03-contract.md` §Audit events; `05-ui.md` screen 9
**Dependencies:** Tickets 1–6
**Forbidden edits:** none beyond the above files

### Work
- [ ] Audit viewer: filters (actor, object type, action, date range, free text), pagination, watermarked export (password.confirm).
- [ ] Architecture tests (`tests/Architecture/`):
  - controllers never reference the `Storage` facade;
  - `audit_events` inserts occur only inside `AuditLogger` (scan `app/` for `AuditEvent::create` / `DB::table('audit_events')` outside it);
  - every `routes/web.php` route except the explicit guest list carries `auth` middleware;
  - leak-sentinel scan: privileged strings absent from test-rendered views for denied actors.
- [ ] Review checklist walk of the contract's route table: authorization-before-data-access on every route.
- [ ] Verdict output for every claim saved under `07-evidence/`; retrospective notes.

### Acceptance criteria
- [ ] `test_audit_viewer_forbidden_for_non_admin` (C-13)
- [ ] ALL brief verdicts C-01–C-14 green with evidence linked
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`
- [ ] CI green on the feature branch

---

<RULES:>
- A ticket is complete only when its own verdict AND the standing architecture/style gates are green.
- A failing test is fixed at the cause — assertions are never weakened to get green; if a governing decision changed, update the decision first, then the test.
- Stop on contract drift: if implementation reveals a contract error, update and re-approve the artifact before continuing.
- No opportunistic cleanup: baseline debt is either scoped into the brief or left alone.
- `05-ui-mockup.html` approval (by Ian) is required before any Blade work in T-04/T-05/T-07 begins — see 001-D06.
