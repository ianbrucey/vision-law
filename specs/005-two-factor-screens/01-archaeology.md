# 005 — Two-factor authentication screens — Archaeology

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## REUSE / EXTEND / NEW / CONFLICT

| Area | Item | Verdict | Notes |
|---|---|---|---|
| Routes | `POST user/two-factor-authentication` (`two-factor.enable`), `POST user/confirmed-two-factor-authentication` (`two-factor.confirm`), `DELETE user/two-factor-authentication` (`two-factor.disable`) | REUSE | Fortify defaults; confirm:true and confirmPassword:true per 001-D01 config |
| Routes | `GET user/two-factor-qr-code` (`two-factor.qr-code`), `GET user/two-factor-secret-key`, `GET/POST user/two-factor-recovery-codes` | REUSE | QR served as SVG; secret-key route exists but the UI uses the QR route only (005-D04) |
| Routes | `POST two-factor-challenge` (`two-factor.login.store`) | REUSE | Custom `Auth\TwoFactorChallengeController` adds brute-force lockout (C-03); unchanged |
| Routes | `GET two-factor-challenge` named `two-factor.login` | NEW | Missing today (`views => false`); Fortify's post-password redirect targets this name and currently 404s |
| Routes | `GET user/two-factor` enrollment page | NEW | Name: `two-factor.settings`; authenticated |
| Service | `LoginAttemptService` (lockout, email normalization) | REUSE | Challenge controller already consults it |
| Listener | `EnforceSessionPolicies` (mfa_required denial for org admins) | EXTEND | 005-D01 changes the denial shape to a restricted setup-mode session; the policy (admins must enroll) is unchanged |
| Model | `User` — `two_factor_secret`, `two_factor_recovery_codes` (hidden, encrypted by Fortify's actions), `two_factor_confirmed_at` (datetime cast) | REUSE | Columns verified present 2026-10-07; no migration |
| Components | `x-ui.card`, `x-ui.field`, `x-ui.button`, `x-ui.banner`, `x-ui.page-header`, `x-ui.empty`, `x-ui.modal` | REUSE | From the decided 002 inventory |
| Layouts | `layouts/app.blade.php` (authenticated shell) | REUSE | Enrollment page lives inside the app shell |
| Tests | `tests/Feature` 2FA backend tests (001 C-06) | REUSE | Backend verdicts stay green; this spec adds UI feature tests only |
| Tests | `VisionUiTest` architecture doors | EXTEND | New views must pass the existing doors; no new doors needed |
| Audit | `AuditLogger` | REUSE | Contract names the new events; no new infrastructure |
| CONFLICT | `EnforceSessionPolicies` currently logs the user out and throws 403 on the Login event | CONFLICT → resolved by 005-D01 | The logout+403 makes enrollment unreachable (the deadlock Ian hit). The restricted-session replacement is the whole point of T-01 |

## Files to touch

| File | Action | Reason |
|---|---|---|
| `routes/web.php` | Add `GET two-factor-challenge` (`two-factor.login`) and `GET user/two-factor` (`two-factor.settings`) | The two missing views |
| `app/Listeners/EnforceSessionPolicies.php` | Replace logout+403 with restricted setup-mode session + redirect | 005-D01 deadlock fix |
| `app/Http/Middleware/RestrictToTwoFactorSetup.php` | NEW | Allowlist enforcement for setup-mode sessions |
| `app/Http/Controllers/Auth/TwoFactorSettingsController.php` | NEW | Enrollment page: status, QR embed, recovery-codes once-display |
| `resources/views/auth/two-factor-settings.blade.php` | NEW | Enrollment UI |
| `resources/views/auth/two-factor-challenge.blade.php` | NEW | Challenge UI |
| `bootstrap/app.php` (middleware registration) | Extend | Register the setup-mode middleware alias |
| `tests/Feature/TwoFactorScreensTest.php` | NEW | C-01–C-08 UI verdicts |

## Protected files (do not edit)

- `app/Services/InvitationService.php`, `app/Actions/Fortify/CreateNewUser.php` — unrelated auth surfaces.
- `app/Http/Controllers/Auth/TwoFactorChallengeController.php` — the POST challenge logic is correct; only the missing GET view is added.
- `vendor/laravel/fortify/*` — framework code; wire, don't fork.
- `config/fortify.php` `views => false` — stays; the new views are ours, not Fortify's.
- `app/Models/User.php` casts/hidden — verified correct; no changes needed.

## Baseline

Last known green: 124 tests / 1132 assertions post-003, plus spec 004's additions (004 merged 2026-10-07; exact count captured at T-01 start by running the full suite). `pint --test`, PHPStan level 7, and `npm run build` were green at the 004 merge. No baseline-debt cleanup in scope.
