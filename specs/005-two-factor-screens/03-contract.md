# 005 — Two-factor authentication screens — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Schema:** `02-schema-delta.md` (no delta)

> Routes, the setup-mode session flow, validation, errors, authorization, and
> audit are decided here — not during implementation. Views may request only
> what this contract exposes.

---

## Domain service methods

No new domain services. All 2FA mutations go through Fortify's existing actions
(enable / confirm / disable / recovery-code regeneration) and the existing
`Auth\TwoFactorChallengeController@store`. UI code never touches the
`two_factor_*` columns directly.

<RULE: the setup-mode flag lives in the session only (`visionlaw.2fa_setup_required`);
it is never persisted to the user row.>

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `user/two-factor` | `TwoFactorSettingsController@index` (new) | `auth` | Any authenticated user; renders the enrollment page (fresh / already-enrolled / setup-mode states) |
| GET | `two-factor-challenge` | Blade view (new) | guest-with-challenge | Only when a challenged-user session exists (`$request->hasChallengedUser()`); otherwise redirect to `login`. Name MUST be `two-factor.login` — Fortify's post-password redirect targets this name (005-D02) |
| POST | `two-factor-challenge` | `Auth\TwoFactorChallengeController@store` (existing) | guest-with-challenge | Existing: challenged-user session required → `invalid_challenge` 422; brute-force lockout → 429 |
| POST | `user/two-factor-authentication` | Fortify (existing) | `auth` | Existing |
| POST | `user/confirmed-two-factor-authentication` | Fortify (existing) | `auth` | Existing; on success the setup-mode flag is cleared (005-D01) |
| DELETE | `user/two-factor-authentication` | Fortify (existing) | `auth` + password confirmation | Existing `confirmPassword` behavior; surfaced in the UI (C-05) |
| GET | `user/two-factor-qr-code` | Fortify (existing) | `auth` | Existing; the enrollment view embeds it via `<img>` (005-D04) |

<RULES:>
- Authenticated-by-default; the challenge GET is the only guest exception, and only with a live challenged-user session.
- Setup-mode allowlist (005-D01): when `visionlaw.2fa_setup_required` is set, the ONLY permitted routes are `two-factor.settings` (+ its POST/DELETE actions), `two-factor.qr-code`, `two-factor.secret-key`, `logout`, and `/up`. Every other request redirects to `two-factor.settings`. Enforced in `RestrictToTwoFactorSetup` middleware — allowlist, never denylist.
- Denials: setup-mode violations redirect (302) to enrollment — no 403/404 games needed since the session is authenticated and the restriction is explained on the page itself.

## The setup-mode flow (005-D01)

1. `EnforceSessionPolicies` on the Login event: if the user is `org_admin` AND has no confirmed 2FA, then instead of logout+403: set `visionlaw.2fa_setup_required = true` in the session, audit `auth.login.2fa_enrollment_required`, and redirect to `two-factor.settings`.
2. `RestrictToTwoFactorSetup` middleware (on the `auth` group, after authentication): if the flag is set and the route is not allowlisted → 302 to `two-factor.settings`.
3. On successful `two-factor.confirm`: clear the flag. The user now holds a full session.
4. Non-admin users without 2FA: unchanged — no flag, no redirect (C-06).

## Requests & validation

### POST `user/confirmed-two-factor-authentication` (existing Fortify)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `code` | string | yes | 6-digit TOTP against the pending secret | Generic failure — the UI shows "That code didn't work. Check your clock and try again." (no distinction between wrong code and expired pending secret) |

### POST `two-factor-challenge` (existing)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `code` | string | one of `code`/`recovery_code` | valid TOTP for the challenged user | Generic: "That code didn't work." — identical for bad TOTP, bad recovery code, or consumed recovery code (no enumeration, matching the existing `invalid_credentials` tone) |
| `recovery_code` | string | one of `code`/`recovery_code` | unused recovery code | Same generic error as above |

## Audit events

| Event | When | Actor |
|---|---|---|
| `auth.login.2fa_enrollment_required` | Setup-mode restricted session issued (005-D01) | the org_admin |
| `mfa.enrolled` | `two-factor.confirm` succeeds | the user |
| `mfa.confirm.failed` | confirm code rejected | the user (generic, no code detail logged) |
| `mfa.challenge.succeeded` | challenge POST accepted | the challenged user |
| `mfa.challenge.failed` | challenge code rejected | the challenged user (generic, no code detail logged) |
| `mfa.recovery_codes.regenerated` | regeneration POST | the user |
| `mfa.disabled` | disable after password confirmation | the user |
| Setup-mode route denial | allowlist violation → redirect | the user (attributes: attempted path) |

## Leak sentinels

- The plaintext TOTP secret and any `otpauth://` URI must never appear in HTML source, JSON responses, or logs. The enrollment view renders the QR via `<img src="…two-factor-qr-code">` (005-D04) — never inline SVG, never the secret-key route output in page source.
- Recovery codes appear exactly once: in the post-confirm response. They must never render on later visits, in emails, or in logs.
- `two_factor_secret` / `two_factor_recovery_codes` values must never be serialized (the `$hidden` array already covers this; the sentinel test asserts it on the new views).
