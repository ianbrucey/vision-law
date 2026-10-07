# 001 — Foundation — verdict evidence (T-07 freeze)

**Date:** 2026-10-06 · **Branch:** `feat/001-foundation-auth-rbac-audit`
**Raw logs:** `verdicts.log` (13 named verdicts), `architecture.log` (C-14 suite)

## Claim → verdict → result

| Claim | Verdict (named test) | Result |
|---|---|---|
| C-01 | `test_registration_requires_email_verification_before_login` | ✅ pass |
| C-02 | `test_weak_and_breached_passwords_rejected` | ✅ pass |
| C-03 | `test_brute_force_lockout_after_five_failures` | ✅ pass |
| C-04 | `test_password_reset_revokes_sessions_and_uses_generic_responses` | ✅ pass |
| C-05 | `test_invitation_token_rejected_for_different_signed_in_user` | ✅ pass |
| C-06 | `test_mfa_challenge_blocks_login_without_valid_code` | ✅ pass |
| C-07 | `test_session_revoke_and_concurrent_session_limit` | ✅ pass |
| C-08 | `test_cross_org_matter_access_returns_404` | ✅ pass |
| C-09 | `test_team_grant_gives_matter_access` | ✅ pass |
| C-10 | `test_outside_counsel_cannot_list_org_users` | ✅ pass |
| C-11 | `test_expired_matter_grant_denies_access` | ✅ pass |
| C-12 | `test_audit_log_rejects_update_and_delete` | ✅ pass |
| C-13 | `test_audit_viewer_forbidden_for_non_admin` | ✅ pass (new in T-07) |
| C-14 | `tests/Architecture` suite green (8 tests, 149 assertions) | ✅ pass (new in T-07) |

Verdict run: **13 passed, 192 assertions** (`verdicts.log`).
Architecture suite: **8 passed, 149 assertions** (`architecture.log`) —
doors: (1) no `Storage` facade in controllers, (2) `audit_events` writes
only via `AuditLogger`, (3) authenticated-by-default with explicit guest
list, (4) leak-sentinel scan for denied actors.

## Standing gates (T-07 final)

| Gate | Result |
|---|---|
| `php artisan test` (full suite) | ✅ 73 passed, 706 assertions |
| `vendor/bin/pint --test` | ✅ 102 files pass |
| `vendor/bin/phpstan analyse --no-progress` (level 7) | ✅ no errors |
| `npm run build` | ✅ builds (asset pipeline verified; no frontend changes) |

Note: `npm run build` initially failed with `vite: not found` because
`node_modules` was never installed on this checkout — `npm install` was run
once to satisfy the gate (new untracked `package-lock.json` committed).
No frontend source was changed.

## Route-table review walk

`route-review.md` — 26 contract routes walked (30 rows incl. absent-by-design
GET view routes): **30/30 pass, 0 flags, 0 fixes.** 4 observations recorded
(`GET /` landing page, `GET /up` health check, framework `storage/{path}`
file-serving with no middleware, headless-mode absent GET view routes).
