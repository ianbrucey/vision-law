# 008 — Passkey authentication (WebAuthn) — Implementation plan

**Status:** APPROVED (brief approved by Ian Bruce 2026-10-08; council decisions 008-D01…D10 recorded)
**Author:** Lotus (build worker)
**Date:** 2026-10-08
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out tickets. A ticket is done only when its verdicts are
> green in isolation AND the architecture suite, Pint, and PHPStan pass.
> **Every test run on the dev-server checkout starts with
> `php artisan optimize:clear`** (the live deploy's route cache otherwise
> poisons flag-dependent tests — see 01-archaeology.md baseline note).
> Never migrate/seed the live `vision_law` database (commandment 8); tests
> run against `vision_law_test` via RefreshDatabase.

---

## Ticket 0 — Dependency (DONE at archaeology)

- [x] `composer require laragear/webauthn` → v5.0.2; committed alone so the
      dependency delta is reviewable (`39e202f`).

## Ticket 1 — Schema, config, user model

**Files:** `database/migrations/2026_10_08_080001_create_webauthn_credentials_table.php` (published from Laragear, renamed), `config/webauthn.php` (published), `app/Models/User.php` (trait + contract + `hasPasskeys()`), `phpunit.xml` (pin `WEBAUTHN_ID=localhost`, `WEBAUTHN_ORIGINS=http://localhost`)
**Inputs:** 02-schema-delta.md; 008-D03, 008-D08
**Dependencies:** T-00
**Forbidden edits:** `config/fortify.php`, `app/Models/User.php` `$hidden`/casts, any existing migration

### Work
- [ ] Publish Laragear config + migration (`vendor:publish`); rename the migration into the 008 sequence; confirm the published migration defers to `WebAuthnCredential::migration()` and keep it that way (schema stays the package's single source)
- [ ] `User`: `use WebAuthnAuthentication;` + `implements WebAuthnAuthenticatable` + `hasPasskeys()` (exists over the credentials relation)
- [ ] phpunit.xml env pins so ceremonies are hermetic on server and CI alike

### Acceptance criteria
- [ ] Migration runs on `vision_law_test`; schema matches 02-schema-delta.md
- [ ] `hasPasskeys()` false for a fresh user; full suite still 221 green · Pint · PHPStan

## Ticket 2 — Registration & management (enrollment surface)

**Files:** `app/Http/Controllers/Auth/PasskeyController.php` (new), `app/Http/Controllers/Auth/TwoFactorSettingsController.php` (extend), `resources/views/auth/two-factor-settings.blade.php` (extend — passkey section), `app/Http/Middleware/RestrictToTwoFactorSetup.php` (allowlist), `app/Listeners/EnforceSessionPolicies.php` (predicate), `routes/web.php` (3 management routes), `resources/js/passkey.js` (create) + `vite.config.js` (input) + settings view `@vite` include, `tests/Helpers/VirtualAuthenticator.php` (new), `tests/Feature/PasskeyRegistrationTest.php` (new)
**Inputs:** 03-contract.md §Ceremonies (registration, revocation) + §Routes; 04-fixtures.json U-PK-01/08; 008-D02, D07, D08, D10
**Dependencies:** T-01
**Forbidden edits:** `tests/Feature/TwoFactorScreensTest.php` (and all 005 tests), `app/Http/Responses/*`, Fortify actions, `two-factor-challenge.blade.php` (T-03 owns it)

### Work
- [ ] `VirtualAuthenticator`: deterministic EC P-256 keypair; builds attestation responses (fmt `none`, UV set) and assertion responses (authData + ECDSA signature) in Laragear's JSON transport shape, parameterized by RP ID / origin / challenge — the only ceremony driver tests use
- [ ] `PasskeyController@registerOptions` → `AttestationRequest->secureRegistration()->toCreate()`
- [ ] `PasskeyController@store` (type-hint `AttestedRequest`): validate `alias` (008-D10) → `save(['alias' => …])` → audit `mfa.passkey.registered` → first-factor recovery codes when the column is null (008-D02: 8 codes, Fortify's exact storage shape, flash once-display, audit issuance) → clear setup flag when MFA now satisfied → JSON `{redirect}`
- [ ] `PasskeyController@destroy`: resolve inside `$user->webAuthnCredentials()` (other users' ids 404), `password.confirm` middleware on the route, delete row (008-D08), audit `mfa.passkey.revoked`, redirect with toast
- [ ] Settings controller + view: passkey section per 05-ui.md (list, add form, revoke forms, setup-mode both-paths copy, unsupported-browser state via JS)
- [ ] Allowlist += the 3 route names; `EnforceSessionPolicies` predicate += `! hasPasskeys()`

### Acceptance criteria (binary verdicts from 00-brief.md)
- [ ] C-01 — `test_setup_mode_offers_passkey_and_totp_enrollment`
- [ ] C-02 — `test_user_registers_passkey_via_ui`
- [ ] C-03 — `test_passkey_enrollment_clears_setup_mode` (incl. recovery codes issued + shown once, 008-D02)
- [ ] C-06 (management half) — `test_user_can_revoke_passkey`
- [ ] C-11 (registration/revoke half) — audit assertions inside the tests above + `test_passkey_events_are_audited` (registration/revoke rows)
- [ ] 005 suite unmodified and green · Architecture suite · Pint · PHPStan

## Ticket 3 — Login challenge (assertion path)

**Files:** `app/Actions/Fortify/RedirectIfTwoFactorAuthenticatable.php` (extend), `app/Http/Controllers/Auth/PasskeyChallengeController.php` (new), `routes/web.php` (2 challenge routes + challenge GET view data), `resources/views/auth/two-factor-challenge.blade.php` (extend), `resources/js/passkey.js` (extend — assertion flow), `tests/Architecture/AuthenticatedByDefaultTest.php` (guest-list), `tests/Feature/PasskeyChallengeTest.php` (new)
**Inputs:** 03-contract.md §Ceremonies (challenge) + §Routes; 04-fixtures.json U-PK-02…07; 008-D06, D07
**Dependencies:** T-02 (credentials + VirtualAuthenticator exist)
**Forbidden edits:** `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `app/Listeners/AuthEventSubscriber.php` (mirror its shapes, don't change them), 005 tests

### Work
- [ ] Redirect action: parent first (TOTP path byte-identical); passkey-only users get Fortify's challenge response (session `login.id`/`login.remember`, challenged event, redirect/423)
- [ ] `PasskeyChallengeController@options`: challenged session required (422 `invalid_challenge` otherwise) → `AssertionRequest->secureLogin()->toVerify($challengedUser)`
- [ ] `PasskeyChallengeController@store`: lockout consultation (429 like the TOTP store) → `AssertionValidator` over `AssertionValidation` bound to the challenged user → success: 008-D06 completion (clear counters, `last_login_at`, guard login with `login.remember`, forget challenge session keys, regenerate, audits, intended redirect) / failure: record failure, audits, generic 422 `{code: "invalid_passkey"}`
- [ ] Challenge view: passkey section when `$hasPasskey` (heading adapts; primary/secondary swap per 05-ui.md; generic error slot shared)
- [ ] Guest-list the two POST routes in `AuthenticatedByDefaultTest` with the contract citation

### Acceptance criteria
- [ ] C-04 — `test_login_challenge_accepts_passkey_assertion`
- [ ] C-05 — `test_passkey_challenge_failure_is_generic` (incl. U-PK-06 cross-user assertion)
- [ ] C-06 (login half) — `test_admin_revoking_last_factor_returns_to_setup_mode`
- [ ] C-08 — `test_assertion_from_wrong_origin_is_rejected`, `test_challenge_replay_is_rejected`
- [ ] C-09 — `test_user_with_both_factors_can_use_either` + 005 suite unmodified and green
- [ ] C-11 (challenge half) — challenge audit rows asserted in the tests above
- [ ] Architecture suite · Pint · PHPStan

## Ticket 4 — Hardening: leak sentinel + mobile gate

**Files:** `tests/Feature/PasskeySecurityTest.php` (new), `tests/Feature/PasskeyMobileTest.php` (new), screenshots → `specs/008-passkey-authentication/10-mobile-*.png`
**Inputs:** 03-contract.md §Leak sentinels; 05-ui.md §Mobile gate; 008-D09
**Dependencies:** T-02, T-03
**Forbidden edits:** `tests/Feature/DocumentMobileTest.php`, `tests/Feature/TwoFactorScreensTest.php`

### Work
- [ ] C-07 sentinel: scan `webauthn_credentials` dump, enrollment HTML (fresh / with-passkeys / setup-mode), challenge HTML + options JSON, and the log for private-key markers, raw ceremony payloads, and challenge values
- [ ] C-10 gate: replicate the 007 technique (CHROMIUM_BIN + skip, portable staging, probe) over enrollment-fresh, enrollment-with-passkeys, challenge-with-passkey at 390px

### Acceptance criteria
- [ ] C-07 — `test_passkey_storage_contains_no_private_material`
- [ ] C-10 — `test_mobile_layout_includes_passkey_screens`
- [ ] **Full suite green** · Pint `--test` clean · PHPStan level 7 clean · `npm run build` succeeds

## Freeze (orchestrator, at merge)

- Fold `webauthn_credentials` into `docs/DOMAIN_MODEL.md` (per 02-schema-delta.md).
- ROADMAP: passkeys leave the "Deferred from v1" list.
- Freeze checklist += real-device smoke test on the production domain (008-D03) before passkeys are advertised externally.
- Remove spec-folder mobile screenshots policy check: keep 008's `10-mobile-*.png` beside the spec like 007's.
