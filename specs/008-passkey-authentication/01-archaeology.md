# 008 — Passkey authentication (WebAuthn) — Repository archaeology

**Status:** APPROVED (brief approved by Ian Bruce 2026-10-08; archaeology executed same day)
**Author:** Lotus (build worker)
**Date:** 2026-10-08
**Brief:** `00-brief.md` (APPROVED)

> Read the code — do not guess from memory. Versions recorded from the actual lockfiles where checked.

---

## Baseline (recorded before any 008 code)

- Branch: `feat/008-passkey-authentication` from `main` @ `ad686df` (007 live + approved 008 brief).
- Test suite at baseline: **221 passed / 3659 assertions** (the 007 freeze count), `vendor/bin/pint --test` clean, `vendor/bin/phpstan analyse` (level 7) clean, `npm run build` green. No baseline-debt cleanup in scope.
- **Environment trap found at baseline (not a regression):** the 007 deploy's `optimize` step left a route cache (`bootstrap/cache/routes-v7.php`) in this server checkout, which made `RegistrationFlagTest` fail (cached `/register` routes ignore the env flag). `php artisan route:clear` restores 221/221. **Every test run on this checkout must start with `php artisan optimize:clear`.** CI is unaffected (fresh clone, no cache).
- Only pre-baseline change: `composer require laragear/webauthn` → **v5.0.2** (PHP ^8.3, illuminate/support 12.*|13.* — matches PHP 8.4 / Laravel 13), committed as T-00.

## Findings

| Thing | Location | Verdict | Constraint / note |
|---|---|---|---|
| Fortify headless backend; custom login pipeline `EnsureLoginNotLockedOut → AttemptLogin → RedirectIfTwoFactorAuthenticatable → CompleteLogin` | `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/*` | EXTEND | Our `RedirectIfTwoFactorAuthenticatable` already overrides `validateCredentials()` to reuse AttemptLogin's user. 008 extends its `handle()` for the passkey-only case (below) |
| Challenge trigger keys off the TOTP secret only | Fortify `RedirectIfTwoFactorAuthenticatable` (vendor) | CONFLICT → resolved by 008-D06 design | A passkey-only user would sail through password-only login today — no challenge. Our subclass adds: no TOTP challenge applies **and** user has ≥1 passkey → same challenge response (session `login.id` + redirect to `two-factor.login`). TOTP users' path is byte-identical (parent handles it first) |
| Challenge completion (TOTP + recovery code), brute-force lockout | `app/Http/Controllers/Auth/TwoFactorChallengeController.php` + Fortify `TwoFactorAuthenticatedSessionController` | REUSE (unmodified) | **Protected.** Recovery-code completion already works for any challenged user — Fortify's `TwoFactorLoginRequest::validRecoveryCode()` checks the codes array only, never the TOTP secret (verified in vendor source). This is what makes 008-D02 cheap |
| `EnforceSessionPolicies` (Login event): org_admin without `hasEnabledTwoFactorAuthentication()` → setup-mode session | `app/Listeners/EnforceSessionPolicies.php` | EXTEND | One predicate widening: `&& ! $user->hasPasskeys()`. The listener fires for the passkey challenge login too (guard `login()`), so no second enforcement point exists or is needed |
| Setup-mode allowlist middleware | `app/Http/Middleware/RestrictToTwoFactorSetup.php` | EXTEND | Add the three passkey management route names to the explicit allowlist (`passkeys.register.options`, `passkeys.register`, `passkeys.destroy`). Nothing else changes — allowlist, never denylist (005-D01) |
| Setup-flag clearing on TOTP confirm | `app/Http/Responses/TwoFactorConfirmedResponse.php` (bound over Fortify's contract) | REUSE pattern | The passkey register controller performs the same `session()->forget(SESSION_KEY)` when the first factor lands (C-03) |
| Enrollment page (controller + view, 4 states) | `app/Http/Controllers/Auth/TwoFactorSettingsController.php`, `resources/views/auth/two-factor-settings.blade.php` | EXTEND | Controller gains `passkeys` + `hasPasskeyFactor` view data. View gains ONE new section after the existing branch block (passkey card: list + add + revoke) rendered in every state except the recovery-codes once-display. All 005 states/copy untouched, so the 005 suite passes unmodified |
| Challenge page (guest view) | `routes/web.php` GET `two-factor-challenge` closure + `resources/views/auth/two-factor-challenge.blade.php` | EXTEND | Closure additionally passes `hasPasskey` for the challenged user. View gains a passkey section (button + `passkey.js`) rendered only when the challenged user has passkeys; the generic error slot is shared (005-D05) |
| Recovery codes: generation format | `app/Actions/Fortify/EnableTwoFactorAuthentication.php` | REUSE pattern | 8 codes, `RecoveryCode::generate()`, stored `Fortify::currentEncrypter()->encrypt(json_encode([...]))` in `two_factor_recovery_codes`. 008-D02 issues the identical shape at first-passkey time when the column is null. Once-display: `RestrictToTwoFactorSetup::RECOVERY_CODES_FLASH_KEY` flash + the settings controller's existing handling — zero new display code |
| Revoke/disable password gate | Fortify `confirmPassword` feature + `password.confirm` middleware (already used by `two-factor.qr-image`) | REUSE | `DELETE user/passkeys/{credential}` carries `password.confirm`; refusal redirects to the existing `password.confirm` view route (005 T-03) |
| `User` model | `app/Models/User.php` | EXTEND | Adds Laragear's `WebAuthnAuthentication` trait + `WebAuthnAuthenticatable` contract, and `hasPasskeys(): bool`. UUID PKs satisfy Laragear's `webAuthnId(): UuidInterface`. `$hidden`/casts untouched |
| Audit | `app/Services/AuditLogger.php` + `app/Listeners/AuthEventSubscriber.php` | REUSE | Events written from the two new controllers via AuditLogger (the sole writer). **Constraint:** payload keys may not contain `password`/`secret`/`token`/`hash`/`recovery` (blocklist) — passkey payloads carry `actor_id` + `credential_label` only |
| Laragear WebAuthn v5.0.2 | `vendor/laragear/webauthn` | NEW (dependency) | README declares itself superseded by `laravel/passkeys` and unmaintained — evaluated, kept per 008-D01. **Usage surface:** `AttestationRequest::secureRegistration()->toCreate()` (options JSON; challenge stored in session), `AttestedRequest::save(['alias' => …])` (validates + persists), `AssertionRequest::secureLogin()->toVerify($user)` (options scoped to a given user), `AssertionValidator` pipeline over `AssertionValidation` (user-bound). **NOT used:** `AssertedRequest::login()`, the `eloquent-webauthn` guard driver, `Routes::register()` (routes are opt-in only — verified nothing is auto-registered), the deprecated vendored JS helper (008-D05) |
| Credential storage | Laragear migration `webauthn_credentials` | NEW (publish + adapt) | Columns: `id` (string PK = credential id), morphs `authenticatable`, `alias` (the label — 008-D10), `counter`, `rp_id`, `origin`, `transports`, `aaguid`, `public_key` (text — a COSE **public** key), `attestation_format`, `certificates`, `disabled_at`, timestamps. Publish via `vendor:publish --tag=migrations`... actually `--tag=webauthn-migrations` (provider's publishesPackageMigrations tag is `migrations` per source: `publishesMigrations([...], 'migrations')`) — the published file lands in `database/migrations/`; renamed to the 008 migration sequence at T-01. No org/matter keys: credentials are user-owned, and tenancy flows from the user (same as `two_factor_*` columns on `users`) |
| RP configuration | `config/webauthn.php` (published) | NEW | `relying_party.id` ← env `WEBAUTHN_ID`; `origins` ← env `WEBAUTHN_ORIGINS`. Tests pin both in `phpunit.xml` (008-D03) |
| Challenge storage | Laragear `SessionChallengeRepository` | REUSE | Single session slot (key `_webauthn`), consumed on validation — gives C-08's single-use property. Ceremonies are sequential in every flow, so attestation/assertion sharing the slot is safe |
| Per-page JS pattern | `resources/js/document-upload.js` + `@vite([...])` in-view | REUSE pattern | `resources/js/passkey.js` becomes a new Vite input; included only by the two auth views that need it (008-D05) |
| Mobile gate technique | `tests/Feature/DocumentMobileTest.php` (007 C-15) | REUSE technique | CHROMIUM_BIN env + graceful skip, portable staging dir (`/root` only when writable — snap Chromium), standalone-HTML render + metrics probe. Replicated in `PasskeyMobileTest` per 008-D09; `DocumentMobileTest` itself is NOT edited |
| Architecture doors | `tests/Architecture/*` (`AuthenticatedByDefaultTest`, `LeakSentinelTest`, `AuditWritesTest`, `VisionUiTest`) | EXTEND (test-side only) | The two guest challenge POST routes join `AuthenticatedByDefaultTest`'s explicit guest list with a contract citation (same move as 007-D19 for share links); new views must pass VisionUiTest doors; new audit events must satisfy AuditWritesTest (written via AuditLogger only) |
| Fixture style | `tests/Helpers/FixtureLoader.php`, `specs/005-*/04-fixtures.json` | REUSE | 008 fixtures declared in `04-fixtures.json`; test users built via the 005 test pattern (`makeUnenrolledOrgAdmin` shape) + a deterministic virtual authenticator helper (`tests/Helpers/VirtualAuthenticator.php`) |
| Old vision Python app (`/opt/vision`), Haven (`/opt/haven`) | — | CONFLICT (do not touch) | Out of scope entirely |

## Architectural doors this feature must use

- All WebAuthn validation goes through Laragear's validator pipelines — no hand-rolled signature/RP/challenge checks in app code.
- Audit events are written only through `AuditLogger`, with blocklist-safe payload keys.
- Login completion (any factor) goes through the guard so the `Login` event — and therefore `EnforceSessionPolicies` — always runs.
- The setup-mode surface grows only by explicit allowlist entries.
- No test may depend on a real authenticator, browser, daemon, or the production domain: ceremonies are driven by a deterministic virtual authenticator (EC P-256 keypair, `none` attestation, UV flag set) against the pinned test RP ID.

## Files to touch

| File | Action | Owner | Reason |
|---|---|---|---|
| `config/webauthn.php` | create (publish) | T-01 | RP config from env (008-D03) |
| `database/migrations/2026_10_08_080001_create_webauthn_credentials_table.php` | create (published, renamed) | T-01 | Credential storage (02-schema-delta) |
| `app/Models/User.php` | edit | T-01 | Laragear trait + contract + `hasPasskeys()` |
| `phpunit.xml` | edit | T-01 | Pin `WEBAUTHN_ID`/`WEBAUTHN_ORIGINS` for hermetic ceremonies |
| `app/Http/Middleware/RestrictToTwoFactorSetup.php` | edit | T-02 | Allowlist += passkey management routes |
| `app/Listeners/EnforceSessionPolicies.php` | edit | T-02 | MFA predicate widened with `hasPasskeys()` |
| `app/Http/Controllers/Auth/PasskeyController.php` | create | T-02 | Register options + store (+ first-factor recovery codes, 008-D02) + destroy |
| `app/Http/Controllers/Auth/TwoFactorSettingsController.php` | edit | T-02 | Passkey view data |
| `resources/views/auth/two-factor-settings.blade.php` | edit | T-02 | Passkey section (list/add/revoke) |
| `app/Actions/Fortify/RedirectIfTwoFactorAuthenticatable.php` | edit | T-03 | Passkey-only users get the challenge redirect |
| `app/Http/Controllers/Auth/PasskeyChallengeController.php` | create | T-03 | Assertion options + store (login completion, 008-D06) |
| `routes/web.php` | edit | T-02/T-03 | Passkey management + challenge routes; challenge GET passes `hasPasskey` |
| `resources/views/auth/two-factor-challenge.blade.php` | edit | T-03 | Passkey section on the challenge page |
| `resources/js/passkey.js` + `vite.config.js` | create/edit | T-03 | Browser ceremonies (008-D05) |
| `tests/Helpers/VirtualAuthenticator.php` | create | T-02 | Deterministic ceremony driver for tests |
| `tests/Feature/PasskeyRegistrationTest.php` | create | T-02 | C-01, C-02, C-03, C-06 (management half), C-11 (registration/revoke events) |
| `tests/Feature/PasskeyChallengeTest.php` | create | T-03 | C-04, C-05, C-08, C-09, C-11 (challenge events) |
| `tests/Feature/PasskeySecurityTest.php` | create | T-04 | C-07 leak sentinel |
| `tests/Feature/PasskeyMobileTest.php` | create | T-04 | C-10 mobile gate |
| `tests/Architecture/AuthenticatedByDefaultTest.php` | edit | T-03 | Guest-list += passkey challenge POSTs (contract-cited) |

## Protected files (do not edit)

- `tests/Feature/TwoFactorScreensTest.php` and the rest of the 005 suite — must pass **unmodified** (C-09).
- `app/Http/Controllers/Auth/TwoFactorChallengeController.php` — the TOTP/recovery challenge POST is correct as-is.
- `app/Actions/Fortify/EnableTwoFactorAuthentication.php`, Fortify's actions/responses (incl. `app/Http/Responses/TwoFactorConfirmedResponse.php`) — TOTP behavior frozen.
- `config/fortify.php` — no changes; passkeys are not a Fortify feature flag.
- `tests/Feature/DocumentMobileTest.php` — 007's gate is replicated, not edited (008-D09).
- `vendor/laragear/webauthn/*` — wire, don't fork.
- `/opt/vision`, `/opt/haven` — never touched.
- The live `vision_law` database — migrations run against `vision_law_test` only (protocol commandment 8); deployment is the orchestrator's step, not this build's.
