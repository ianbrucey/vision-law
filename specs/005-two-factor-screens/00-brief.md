# 005 — Two-factor authentication screens — Strategic brief

**Status:** APPROVED by Ian Bruce, 2026-10-07 (mockup approved; decisions 005-D01, 005-D02 confirmed)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## Goal

An org admin who must enroll in two-factor authentication can do so entirely through the UI — and complete the 2FA challenge at login — so the invite-only front door Ian decided on is actually open: today the MFA-required policy logs org admins straight back out and no enrollment screen exists.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | An org admin without enrolled 2FA gets a restricted "setup mode" session (not a 403) and can reach only the enrollment page; every other route redirects to enrollment | `test_org_admin_without_2fa_gets_setup_mode_session` — login as unenrolled org_admin returns a redirect to the enrollment page, not 403; GET `/admin/invitations` in setup mode redirects to enrollment; the flag is absent for fully-enrolled users | PLT-05 (MFA required for privileged roles) |
| C-02 | Enrollment via UI: enable → scan QR → enter valid code → 2FA confirmed; the org admin can then log in fully and the setup-mode flag is cleared | `test_org_admin_enrolls_via_ui_and_logs_in_fully` — POST enable, GET QR 200, POST confirm with valid TOTP → `two_factor_confirmed_at` set; subsequent login lands past the challenge with no setup flag | PLT-05 |
| C-03 | The challenge page accepts a valid TOTP code and a valid recovery code; invalid codes fail with the generic error (no enumeration) | `test_challenge_accepts_totp_and_recovery_code` and `test_challenge_rejects_invalid_code_generically` — valid code → authenticated; valid recovery code → authenticated and the code is consumed; wrong code → generic `invalid_credentials`-tone error, no distinction between bad code and bad recovery code | PLT-05 |
| C-04 | Recovery codes are displayed exactly once after confirmation; they are never re-displayed in full afterward | `test_recovery_codes_shown_once_after_confirm` — the confirm response shows the 10 codes; a second visit to the enrollment page shows status only, no codes; regeneration shows the new set once | PLT-05 (10 single-use backup codes, 001 C-06) |
| C-05 | Disabling 2FA requires password re-authentication (existing Fortify `confirmPassword` behavior surfaced in the UI) | `test_disabling_2fa_requires_password_confirmation` — DELETE without recent password confirmation is refused; with it, 2FA is disabled and the event is audited | PLT-05 (001 C-06) |
| C-06 | Non-admin users without 2FA are NOT forced into setup mode; enrollment stays optional for them and the page is reachable from the app | `test_non_admin_without_2fa_logs_in_normally` — attorney without 2FA logs in with no flag and no redirect; the enrollment page renders for them as an optional control | PLT-05 |
| C-07 | The TOTP secret never appears in HTML source, JSON, or logs; the QR is served via the existing `two-factor.qr-code` route only | `test_totp_secret_never_leaks_in_ui` — leak-sentinel scan of enrollment HTML (fresh, enrolled, setup-mode states), challenge HTML, and logs finds no `two_factor_secret` value and no otpauth URI in page source | — (credential-hygiene law) |
| C-08 | All new views use `<x-ui.*>` components only; the enforced doors hold | `VisionUiTest` doors green on the new views (buttons, form controls, page titles, light-only) | — (002-D02 decided UI law) |

## Blueprint anchors

- [D] 001 C-06 (PLT-05) — TOTP MFA: enrollment confirmed by a valid code; **required for org admins**; 10 single-use backup codes; disabling requires password re-auth. This spec is the UI half of that decided claim.
- [D] `EnforceSessionPolicies` — org admins without 2FA are denied. This spec **changes the denial shape** for that one case (005-D01): restricted setup-mode session instead of logout+403. The policy (admins must enroll) is unchanged; only the mechanism becomes shippable.
- [D] Fortify headless backend (001-D01) — all 2FA POST endpoints already exist and stay as-is; this spec adds only the two missing GET views and their route wiring.
- [D] 002-D02 `docs/UI_Standards.md` decided — both screens use the `<x-ui.*>` inventory; light-only (the 003-D01 dark-hero exception does NOT extend here; these are app surfaces).
- [D] Append-only audit — enrollment, confirmation, challenge success/failure, disable, and recovery-code regeneration are audited (contract names the events).
- [P] None load-bearing.
- [O] None load-bearing. Whether non-admin roles should ever be *required* to enroll stays open and out of scope.

## Security classification

- **Actors:** org_admin without 2FA (setup-mode restricted session), any authenticated user (enrollment page), guest with a challenged-user session (challenge page), anonymous (denied).
- **Data classes touched:** confidential (TOTP secret — encrypted at rest by Fortify's own actions, never rendered), confidential (recovery codes — shown once, never stored in readable form beyond Fortify's encrypted column).
- **Allow events (audit):** `auth.login.2fa_enrollment_required` (restricted setup-mode session issued), `mfa.enrolled` (confirmation succeeded), `mfa.challenge.succeeded`, `mfa.recovery_codes.regenerated`, `mfa.disabled`.
- **Denial events (audit):** `mfa.challenge.failed` (wrong code — generic, no enumeration), `mfa.confirm.failed`, setup-mode denial of non-enrollment routes (logged as the existing `auth.login.failed` shape or the restricted-session denial — contract decides).
- **Leak sentinels:** the plaintext TOTP secret and any otpauth:// URI must never appear in HTML source, JSON, or logs; recovery codes must never appear outside the one-time display; `two_factor_secret` / `two_factor_recovery_codes` column values must never be serialized.

## Evidence and fixtures

Canonical fixtures in `04-fixtures.json`: org_admin without 2FA (setup-mode case — mirrors Ian's real account state), org_admin with confirmed 2FA, attorney without 2FA, attorney with 2FA, expired/already-used recovery code. All synthetic.

## Pre-mortem

- **Likely failure:** the setup-mode session is a new privileged state — if the allowlist leaks, a restricted user reaches admin surfaces. Mitigated by 005-D01: explicit route allowlist (not a denylist), enforced in middleware, with a dedicated test hitting every admin route in setup mode.
- **Assumption:** Fortify's `RedirectIfTwoFactorAuthenticatable` redirects to the `two-factor.login` route name after the password step — true in Fortify, but today that route doesn't exist (views=false), so the redirect 404s. This spec registers it (005-D02); the contract pins the name.
- **Unknown:** TOTP clock drift on Ian's phone — Fortify's time-window covers normal drift; the mockup tells users to check their clock on repeated failures. Not a code change.
- **Mitigation:** recovery codes are shown once — if the user closes the page they must regenerate. The UI says this openly (no silent loss); regeneration is one click with password confirmation.

## Non-goals

- WebAuthn/passkeys, SMS codes, or any second factor beyond TOTP + recovery codes.
- Requiring 2FA for non-admin roles (stays open).
- Email delivery of recovery codes (mail is unconfigured; codes are shown on screen only).
- Changing any 2FA business rule — Fortify's actions and the existing challenge controller are reused as-is.
- An account-settings page — the enrollment page stands alone at its own route for now.
