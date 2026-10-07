# Two-factor screens (spec 005) — 2026-10-07

> Progress entry for the spec 005 build on `feat/005-t1-setup-enroll`
> (ticket 1). Decisions live in `specs/005-two-factor-screens/decisions.md`
> — linked, not duplicated.

## Done — Ticket 1 (setup-mode session + enrollment view)

- **EnforceSessionPolicies**: org_admin without confirmed 2FA now gets a
  restricted setup-mode session (005-D01) — `visionlaw.2fa_setup_required`
  in session, `auth.password_confirmed_at` stamped (password proven seconds
  ago; enrollment POSTs sit behind Fortify's `password.confirm`), session
  ID rotated, audited as `auth.login.2fa_enrollment_required`, 302 to
  `two-factor.settings`. Replaces logout+403 for this case only.
- **RestrictToTwoFactorSetup** (new, alias `2fa.setup`, on the web group):
  explicit allowlist — `two-factor.settings` + its POST/DELETE actions,
  `two-factor.qr-code`, `two-factor.secret-key`, `logout`, `/up`; everything
  else 302s to enrollment. Denials audited as `user.2fa_setup.denied`.
- **TwoFactorSettingsController** (new) + `GET user/two-factor` named
  `two-factor.settings` (005-D02): four states — recovery-codes
  once-display (flashed from the POST handler, never re-read from the
  model), setup-mode, fresh, active.
- **TwoFactorConfirmedResponse / RecoveryCodesGeneratedResponse** (new,
  bound in FortifyServiceProvider): clear the setup flag, flash the codes
  for the once-display, redirect to enrollment (JSON: Fortify's 200
  untouched).
- **auth/two-factor-settings.blade.php** (new): per 05-ui.md — fresh,
  setup-mode banner ("Confirm and continue"), active, once-display,
  validation-error states; `<x-ui.*>` only, light-only. QR via
  `<img src="…two-factor.qr-code">` (005-D04); manual key fetched on demand
  via Alpine (never in page source).
- **AuthEventSubscriber**: `TwoFactorAuthenticationConfirmed` → audits
  `mfa.enrolled` (contract event; ticket 3 asserts it).
- Verdicts green: C-01 `test_org_admin_without_2fa_gets_setup_mode_session`
  (incl. allowlist probe over every `admin.*` route), C-02
  `test_org_admin_enrolls_via_ui_and_logs_in_fully`, C-06
  `test_non_admin_without_2fa_logs_in_normally`.
- Rewrote `AdminRbacTest::test_org_admin_without_mfa_gets_mfa_required_on_login`
  → `test_org_admin_without_mfa_gets_setup_mode_session_on_login` (the old
  403 contract was superseded by 005-D01).

## Gates

- `vendor/bin/pint --test`: PASS (121 files)
- `vendor/bin/phpstan analyse --no-progress`: no errors (level 7)
- `php artisan test`: 144 passed, 1469 assertions (full suite)
- `npm run build`: success

## Notes / follow-ups for later tickets

- Fortify's `two-factor.qr-code` endpoint returns JSON `{svg, url}`, not an
  image — the `<img src="…two-factor.qr-code">` from 005-D04 won't render a
  scannable QR in the browser. Enrollment stays completable via the manual
  key fallback. Ticket 3 (C-07) should decide: app-level QR image endpoint
  vs. JS render (the latter cuts against 005-D04's "never inline SVG").
- Fresh-state (non-admin) "Start setup" POSTs to a `password.confirm`-gated
  endpoint with no recent stamp: JSON clients get 423 and can
  POST `/user/confirm-password` first (established pattern); web posts hit
  the pre-existing missing-`password.confirm`-GET-route gap (500). The
  password-confirmation UI surfacing is ticket 3's work (disable flow).
- Test lesson: the auth guard caches its user instance across requests in
  tests — mutating `$admin` via `forceFill` after login leaves the guard's
  copy stale. Compute TOTP from the real generated secret instead.
