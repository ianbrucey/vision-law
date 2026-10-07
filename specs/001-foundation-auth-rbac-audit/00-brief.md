# 001 — Foundation: authentication, RBAC, and audit logging — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-06

> This brief is DRAFT. No implementation work begins until it is APPROVED by
> Ian (product owner). The executing agent never writes its own verdicts —
> these verdicts are the acceptance bar.

---

## Goal

Any user of Vision Law can prove who they are, can only reach the
organizations, matters, and functions their roles and grants allow, and every
access decision — allowed or denied — is recorded in an append-only audit log.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Self-registration with verified email; unverified accounts cannot log in | `test_registration_requires_email_verification_before_login` | PLT-01 |
| C-02 | Password policy enforced: min 12 chars, breached-password check; weak/breached rejected with a generic message | `test_weak_and_breached_passwords_rejected` | PLT-01 |
| C-03 | Email + password login with Argon2id; 5 failures → 15-min lockout with exponential backoff; lockout events logged; success rotates session and clears counters | `test_brute_force_lockout_after_five_failures` | PLT-02 |
| C-04 | Self-service password reset via single-use 1 h token; revokes all sessions; generic responses for unknown emails (no enumeration) | `test_password_reset_revokes_sessions_and_uses_generic_responses` | PLT-03 |
| C-05 | Email invitations (7-day single-use token, email-bound, revocable) drive registration for new users and grant-add for existing users | `test_invitation_token_rejected_for_different_signed_in_user` | PLT-04 |
| C-06 | TOTP MFA: enrollment confirmed by a valid code; required for org admins; 10 single-use backup codes; disabling requires password re-auth | `test_mfa_challenge_blocks_login_without_valid_code` | PLT-05 |
| C-07 | Session management: user-visible session list, per-session revoke, revoke-all, concurrent-session limit (default 5), 12 h absolute lifetime for privileged roles | `test_session_revoke_and_concurrent_session_limit` | PLT-08 |
| C-08 | Organizations are the top tenant boundary: cross-org access to users, matters, or audit data returns 404 (no existence leak) | `test_cross_org_matter_access_returns_404` | PLT-09 |
| C-09 | Teams: named groups for bulk grants; matter grants can target teams; membership changes take effect within 60 s (computed at request time) | `test_team_grant_gives_matter_access` | PLT-10 |
| C-10 | RBAC with five fixed roles (`org_admin`, `attorney`, `paralegal`, `outside_counsel`, `viewer`); single permission-matrix source of truth; `outside_counsel` cannot enumerate org users | `test_outside_counsel_cannot_list_org_users` | PLT-11 |
| C-11 | Per-matter grants (user or team, role override, optional expiry); effective permission = most permissive of org default / team grants / direct grants; `outside_counsel` is default-deny without explicit grants; expired grants deny automatically | `test_expired_matter_grant_denies_access` | PLT-12 |
| C-12 | Append-only audit log: every create/update/delete, grant change, login, and permission change writes an event; denials are audited too; DB-level REVOKE of UPDATE/DELETE; hash-chained rows | `test_audit_log_rejects_update_and_delete` | PLT-13 |
| C-13 | Admin audit viewer: filter by actor/object/action/date/free text, paginated, watermarked export; non-admins get 403 | `test_audit_viewer_forbidden_for_non_admin` | PLT-14 |
| C-14 | Architecture doors hold: controllers never touch the Storage facade; audit events are written only through `AuditLogger`; all routes authenticated by default with explicit guest exceptions | `tests/Architecture` suite green | foundational (Build_Protocol) |

## Blueprint anchors

- [D] Matter is the central object; every matter-owned row carries `org_id` + `matter_id` (PRODUCT_BLUEPRINT §2, Build_Protocol).
- [D] Authorization is enforced server-side on every request; denials are audited (PRODUCT_BLUEPRINT non-negotiables).
- [D] Audit log is append-only with DB-level enforcement (PLT-13; Build_Protocol).
- [D] Git working-copy drafting pattern is out of scope for this feature — no drafting code here (DRAFTING_ARCHITECTURE §9 stop gate).
- [P] UI_Standards is proposed, not decided — auth screens follow it provisionally; deviations are recorded, not silently adopted.
- [O] None load-bearing for this feature. SSO (PLT-06/07) is a separate feature, not an open question for this one.

## Security classification

- **Actors:** `org_admin`, `attorney`, `paralegal`, `outside_counsel`, `viewer`, `anonymous` (guest).
- **Data classes touched:** internal (org directory), confidential (user emails, matter titles), privileged (MFA secrets, backup codes, password hashes — never rendered, never logged).
- **Allow events (audit):** `auth.login`, `auth.logout`, `auth.mfa.enabled`, `auth.password.reset`, `user.created`, `user.role.assigned`, `invitation.accepted`, `matter.grant.created`, `matter.grant.revoked`, `session.revoked`.
- **Denial events (audit):** `auth.login.failed`, `auth.login.locked_out`, `matter.access.denied`, `user.admin.denied`, `audit.viewer.denied`.
- **Leak sentinels:** password hashes, TOTP secrets, backup codes (plaintext), invitation/reset tokens (plaintext), other orgs' user emails, other matters' titles/numbers. These strings must never appear in HTML/JSON/mail/queue payloads/logs/exports/error messages for unauthorized actors. Tests assert their absence.

## Evidence and fixtures

Canonical scenario in `04-fixtures.json`: two orgs ("Sterling & Associates LLP" — home; "Rival Firm PC" — adversarial cross-org), six users spanning all five roles plus a cross-org user, two matters (one with mixed grants, one restricted), one team, and adversarial cases (no-grant user, expired grant, wrong-org user, signed-in-wrong-user invitation acceptance).

## Pre-mortem

- **What could break:** (1) `laravel/fortify` / `spatie/laravel-permission` may not yet tag Laravel 13 support — versions must be verified at execution; fallback is hand-rolled Fortify-equivalent controllers (larger ticket). (2) Spatie's default migrations assume integer keys — they must be published and converted to UUID morph columns. (3) Hash-chained audit rows under concurrency: two writers must not compute the same `prev_hash` — needs a serialization mechanism (advisory lock or gapless sequence); decide in contract, verify with a concurrency test. (4) 404-vs-403 semantics applied inconsistently across endpoints leaks existence — the architecture test can only check the rule exists, not every route; the contract's route table is the checklist and review must walk it. (5) Argon2id at 64 MB × many test logins slows the suite — use a weaker config in the test env explicitly (documented, not silent).
- **What we're assuming:** Postgres with `pgcrypto` (`gen_random_uuid()`); database session driver in production; mailer available for verification/reset/invite emails (queued, sync driver in tests).
- **What we don't know yet:** whether Ian wants self-registration open or invite-only for the first release (PLT-01 describes self-registration; recommend invite-only default with a config flag — needs Ian's call at approval).

## Non-goals

- SSO: SAML 2.0 (PLT-06) and OAuth2 login (PLT-07) — separate feature, separate spec.
- Notifications, digests, transactional email pipeline beyond auth mail (PLT-15–18).
- TLS, encryption at rest, secrets management, backups (PLT-19–22) — infrastructure track, not this feature.
- File transfer hardening, exports, integrations (PLT-23+) — later phases.
- UI mockup: **deferred** — see `05-ui.md` and `decisions.md` (001-D06). Screens are identified; the mockup is built during the approval session with Ian.

## Authorship

- Orchestrator (Lotus) drafted this brief.
- Assigned agent (TBD at execution) reviews and appends concerns, especially to the pre-mortem.
- **Ian approves (DRAFT → APPROVED). No brief, no work.**
