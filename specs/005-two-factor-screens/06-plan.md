# 005 — Two-factor authentication screens — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out tickets. A ticket is done only when its verdicts are green
> in isolation AND the architecture suite, Pint, and PHPStan pass.

---

## Ticket 1 — Setup-mode session + enrollment view

**Files:** `app/Listeners/EnforceSessionPolicies.php` (extend), `app/Http/Middleware/RestrictToTwoFactorSetup.php` (new), `bootstrap/app.php` (register alias), `app/Http/Controllers/Auth/TwoFactorSettingsController.php` (new), `resources/views/auth/two-factor-settings.blade.php` (new), `routes/web.php` (add `GET user/two-factor`)
**Inputs:** 03-contract.md § setup-mode flow + routes; 04-fixtures.json U-2FA-01/02/03
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `config/fortify.php`, `app/Models/User.php`, Fortify vendor code

### Work
- [ ] `EnforceSessionPolicies`: org_admin without confirmed 2FA → set `visionlaw.2fa_setup_required` in session, audit `auth.login.2fa_enrollment_required`, redirect to `two-factor.settings` (replaces logout+403 for this case only)
- [ ] `RestrictToTwoFactorSetup` middleware: explicit allowlist (`two-factor.settings` + its POST/DELETE actions, `two-factor.qr-code`, `two-factor.secret-key`, `logout`, `/up`); everything else 302s to enrollment
- [ ] `TwoFactorSettingsController@index`: three states (fresh / setup-mode / active) + recovery-codes once-display after confirm/regenerate (codes passed from the POST handler, never re-read)
- [ ] Enrollment Blade view per `05-ui.md` (fresh, setup-mode banner, active, once-display, validation-error states)
- [ ] Clear the setup flag on successful `two-factor.confirm`

### Acceptance criteria (binary verdicts from 00-brief.md)
- [ ] C-01 — `test_org_admin_without_2fa_gets_setup_mode_session` (+ allowlist probe: every admin route 302s to enrollment in setup mode)
- [ ] C-02 — `test_org_admin_enrolls_via_ui_and_logs_in_fully`
- [ ] C-06 — `test_non_admin_without_2fa_logs_in_normally`
- [ ] Architecture suite green · Pint green · PHPStan green

## Ticket 2 — Challenge view

**Files:** `routes/web.php` (add `GET two-factor-challenge` named `two-factor.login`), `resources/views/auth/two-factor-challenge.blade.php` (new)
**Inputs:** 03-contract.md § routes + requests; 04-fixtures.json U-2FA-02/04/05
**Dependencies:** T-01 (the redirect target must exist before the password step can land on it)
**Forbidden edits:** same as T-01, plus `EnforceSessionPolicies.php` (T-01 owns it)

### Work
- [ ] Register `GET two-factor-challenge` → `two-factor.login`, guarded: challenged-user session required, else redirect to `login`
- [ ] Challenge Blade view per `05-ui.md` (code form, recovery-code form, generic error state)
- [ ] Verify the full loop: password step → challenge page → valid TOTP → authenticated (org admin with 2FA); recovery code path consumes the code

### Acceptance criteria
- [ ] C-03 — `test_challenge_accepts_totp_and_recovery_code`, `test_challenge_rejects_invalid_code_generically`
- [ ] Challenge without a challenged session redirects to login (U-2FA-05)
- [ ] Architecture suite green · Pint green · PHPStan green

## Ticket 3 — Recovery codes, disable flow, hardening

**Files:** `resources/views/auth/two-factor-settings.blade.php` (extend), `tests/Feature/TwoFactorScreensTest.php` (extend), leak-sentinel additions
**Inputs:** 03-contract.md § leak sentinels + audit events; 04-fixtures.json (all)
**Dependencies:** T-01, T-02
**Forbidden edits:** Fortify vendor code, `config/fortify.php`

### Work
- [ ] Recovery-codes once-display: confirm/regenerate responses render codes exactly once; later visits show status only
- [ ] Disable flow surfaces the existing password-confirmation gate (no new auth logic)
- [ ] Leak-sentinel tests: scan enrollment HTML (all states), challenge HTML, and logs for the TOTP secret / otpauth URIs / recovery-code values outside the one-time display
- [ ] Audit-event assertions for `mfa.enrolled`, `mfa.challenge.failed`, `mfa.disabled`, `mfa.recovery_codes.regenerated`, `auth.login.2fa_enrollment_required`

### Acceptance criteria
- [ ] C-04 — `test_recovery_codes_shown_once_after_confirm`
- [ ] C-05 — `test_disabling_2fa_requires_password_confirmation`
- [ ] C-07 — `test_totp_secret_never_leaks_in_ui`
- [ ] C-08 — `VisionUiTest` doors green on the new views
- [ ] Full suite green · Pint green · PHPStan level 7 clean · `npm run build` succeeds
