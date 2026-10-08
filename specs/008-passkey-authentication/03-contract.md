# 008 — Passkey authentication (WebAuthn) — Contract

**Status:** APPROVED (with the brief, 2026-10-08)
**Author:** Lotus (build worker)
**Date:** 2026-10-08
**Schema:** `02-schema-delta.md` (one new table)
**Decisions:** `decisions.md` (008-D01…D10)

> Routes, ceremonies, validation, errors, authorization, and audit are decided
> here — not during implementation. Views may request only what this contract
> exposes.

---

## Domain services

No new domain service classes. Ceremony logic lives in two thin controllers
over Laragear's pipelines (008-D01):

- `Auth\PasskeyController` — management ceremonies for an authenticated user
  (register options, register, destroy). Owns three side effects the package
  does not: the setup-flag clear on first factor (C-03), first-factor
  recovery-code issuance (008-D02), and audit events.
- `Auth\PasskeyChallengeController` — the second-factor challenge for a
  challenged user (assertion options, assertion store). Owns login completion
  (008-D06).

`User::hasPasskeys(): bool` — true when the user owns ≥1 credential row
(revocation deletes, 008-D08, so existence is the whole rule).

`EnforceSessionPolicies` MFA predicate becomes:
`org_admin && ! hasEnabledTwoFactorAuthentication() && ! hasPasskeys()`.

`App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable::handle()` gains one
branch: parent declines to challenge (no confirmed TOTP) **and** the user
`hasPasskeys()` → return Fortify's own challenge response (session
`login.id`/`login.remember` + redirect to `two-factor.login`).

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `user/two-factor` | `TwoFactorSettingsController@index` (extended) | `auth` | Any authenticated user. View data gains `passkeys` (own credentials: id, alias, created_at, updated_at) + `hasPasskeyFactor` |
| POST | `user/passkeys/register/options` | `PasskeyController@registerOptions` — name `passkeys.register.options` | `auth` | Any authenticated user (incl. setup-mode sessions — allowlisted). Returns Laragear attestation options JSON; challenge stored server-side in session |
| POST | `user/passkeys` | `PasskeyController@store` — name `passkeys.register` | `auth` | Any authenticated user (allowlisted). Body: ceremony response + optional `alias`. On success: credential stored; first-factor side effects (below); JSON `{redirect}` to `two-factor.settings` |
| DELETE | `user/passkeys/{credential}` | `PasskeyController@destroy` — name `passkeys.destroy` | `auth` + `password.confirm` | **Own credentials only** — the credential is resolved inside the owner's relation; another user's id → 404. Password confirmation required (same gate as 2FA disable, C-06) |
| GET | `two-factor-challenge` | existing closure (extended view data) | guest-with-challenge | Unchanged guard (`hasChallengedUser()`, else redirect to login). View data gains `hasPasskey` for the challenged user |
| POST | `two-factor-challenge/passkey/options` | `PasskeyChallengeController@options` — name `two-factor.passkey.options` | guest-with-challenge | Challenged-user session required, else 422 `invalid_challenge` (mirrors the TOTP store). Returns assertion options scoped to the challenged user's credentials |
| POST | `two-factor-challenge/passkey` | `PasskeyChallengeController@store` — name `two-factor.passkey.store` | guest-with-challenge, `throttle:10,1` | Challenged-user session required → 422 `invalid_challenge`; account lockout consulted first → 429 `locked_out` (mirrors `TwoFactorChallengeController@store`). Valid assertion completes the login (below) |

<RULES:>
- Authenticated-by-default: the two challenge POSTs are guest-surface **only**
  with a live challenged-user session; they join
  `AuthenticatedByDefaultTest`'s explicit guest list with this contract cited
  (the 007-D19 pattern).
- Setup-mode allowlist (005-D01) gains exactly: `passkeys.register.options`,
  `passkeys.register`, `passkeys.destroy`. The challenge routes are guest
  routes and are never reachable from an authenticated setup-mode session's
  allowlist — they don't need to be: a setup-mode session is already
  authenticated.
- Management endpoints never accept a user id: the actor is always
  `$request->user()` / the challenged user from the session. There is no
  admin-manages-others'-passkeys surface in this spec.

## Ceremonies

### Registration (attestation) — enrollment page

1. JS (`passkey.js`) POSTs `passkeys.register.options` →
   `AttestationRequest->secureRegistration()->toCreate()` (UV required,
   008-D07). Response is the standard `PublicKeyCredentialCreationOptions`
   JSON; the challenge is stored server-side (session, single slot).
2. Browser runs `navigator.credentials.create()`; JS POSTs the result plus
   the label to `passkeys.register`.
3. `AttestedRequest` validates (challenge match, RP-ID hash, origin, UV,
   signature/attestation format `none` accepted per 008-D04) and the
   controller calls `->save(['alias' => $label])`.
4. Controller side effects, in order: audit `mfa.passkey.registered`
   → if this is now the user's only factor path **and**
   `two_factor_recovery_codes` is null → issue 8 recovery codes (008-D02),
   flash them under `RECOVERY_CODES_FLASH_KEY`, audit
   `mfa.passkey.recovery_codes.issued` → if the session is setup-mode and the
   user now satisfies MFA → forget the setup flag (C-03).
5. Response: JSON `{redirect: <two-factor.settings URL>}`; the browser
   navigates, and the settings page renders the once-display if codes were
   flashed (existing 005 machinery, unchanged).

### Challenge (assertion) — login

1. Password step succeeds; the extended redirect action sees a passkey-only
   (or passkey-holding, TOTP-less) user and issues the standard challenge
   redirect. (Users with confirmed TOTP are challenged by the parent exactly
   as today — and may still use a passkey at the challenge if they hold one:
   the challenge page shows the passkey section whenever the challenged user
   has credentials, C-09.)
2. JS POSTs `two-factor.passkey.options` →
   `AssertionRequest->secureLogin()->toVerify($challengedUser)` — options
   scoped to that user's credentials only.
3. Browser runs `navigator.credentials.get()`; JS POSTs the result to
   `two-factor.passkey.store`.
4. Controller validates via the `AssertionValidator` pipeline bound to the
   challenged user (challenge single-use from session, RP-ID hash, origin,
   UV, signature, counter strictly increasing — the pipeline syncs the
   counter and stamps the credential).
5. **Success** (008-D06 — Fortify's completion, mirrored): fire Fortify's
   `ValidTwoFactorAuthenticationCodeProvided` (whose subscriber clears the
   brute-force counters, sets `last_login_at`, and audits `auth.login`
   with `mfa_used: true`), `guard->login($user, login.remember)`, forget
   `login.id`/`login.remember`, regenerate the session, audit
   `mfa.passkey.challenge.succeeded`, and respond JSON `{redirect}` where
   the target is `redirect()->intended(Fortify::redirects('login'))` —
   the app-configured post-login target, exactly the TOTP path's.
6. **Failure** (any reason — unknown credential, bad signature, wrong origin,
   replayed challenge, UV absent, user cancellation surfaced as an invalid
   body): record a brute-force failure (lockout bookkeeping, like a bad TOTP
   code), audit `mfa.passkey.challenge.failed` with a coarse `reason`
   category, and return the **generic** failure: JSON 422
   `{code: "invalid_passkey"}` for fetch callers; the page renders the same
   generic sentence as a bad code (005-D05 — "That didn't work. Try again,
   or use a recovery code."). No response ever distinguishes failure reasons.

### Revocation

`DELETE user/passkeys/{credential}` behind `password.confirm` → row deleted →
audit `mfa.passkey.revoked` (label in payload) → redirect to
`two-factor.settings` with the toast naming the consequence. If the revoked
credential was an org admin's last factor, nothing happens to the *current*
session (mirrors TOTP disable); the **next** login lands in setup mode via
`EnforceSessionPolicies` (C-06).

## Requests & validation

### POST `user/passkeys` (register)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `id`, `rawId`, `type` | string | yes | Laragear `AttestedRequest` rules (`type` = `public-key`) | 422 (package validation) |
| `response.clientDataJSON` | string (base64url JSON) | yes | Challenge match + type `webauthn.create` + origin allowlist — package validator | 422 generic |
| `response.attestationObject` | string (base64url CBOR) | yes | RP-ID hash, UV flag, credential parse, format `none` accepted — package validator | 422 generic |
| `alias` | string ≤ 40 | no | Trimmed; control characters stripped; blank → default label (008-D10) | 422 on over-length |

### POST `two-factor-challenge/passkey` (assertion store)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `id`, `rawId`, `type` | string | yes | Must name a credential **owned by the challenged user** | Generic failure (step 6 above) |
| `response.clientDataJSON` | string | yes | Challenge match (single-use) + type `webauthn.get` + origin | Generic failure |
| `response.authenticatorData` | string | yes | RP-ID hash, UV flag, counter > stored counter | Generic failure |
| `response.signature` | string | yes | Verifies against the stored public key | Generic failure |
| `response.userHandle` | string | no | If present, must equal the challenged user's WebAuthn id | Generic failure |

## Audit events

| Event | When | Actor | Payload |
|---|---|---|---|
| `mfa.passkey.registered` | Credential stored | the user | `actor_id`, `credential_label` |
| `mfa.passkey.recovery_codes.issued` | First-factor codes issued at registration (008-D02) | the user | `actor_id`, `credential_label` |
| `mfa.passkey.challenge.succeeded` | Assertion accepted at login | the challenged user | `actor_id`, `credential_label`, `ip` |
| `mfa.passkey.challenge.failed` | Assertion rejected (any reason) | the challenged user | `actor_id`, `reason` (coarse: `unknown_credential` / `validation` / `cancelled_or_malformed`), `ip` |
| `mfa.passkey.revoked` | Credential deleted | the user | `actor_id`, `credential_label` |
| `auth.login` (`mfa_used: true`) | Passkey challenge completes a login | the user | as the existing TOTP path (AuthEventSubscriber shape) |
| `auth.login.failed` | Passkey challenge failure (brute-force bookkeeping parity) | the challenged user | as the existing failure shape (no code detail) |

<RULE: payload keys never contain `password`/`secret`/`token`/`hash`/
`recovery` substrings (AuditLogger blocklist) — hence `credential_label`,
never a credential id in the clear... credential ids are public identifiers,
but the label is what the audit is for; ids stay out.>

## Leak sentinels

- No private-key material exists server-side (protocol property); the sentinel
  proves the negative space: database dump of `webauthn_credentials`,
  enrollment HTML/JSON (all states), challenge HTML/JSON, and logs contain no
  `private_key` markers, no raw `clientDataJSON`/`authenticatorData`/
  assertion payloads, and no challenge values (C-07).
- `public_key`, `aaguid`, `certificates` are never rendered in any view or
  JSON response the app produces. Credential ids appear **only** where the
  protocol or the app's own addressing requires them: in `allowCredentials`
  for the owner's own challenge (the protocol working — it goes only to the
  challenged user's own browser session), and as the revoke form's action
  URL segment on the owner's own settings page (resource addressing, like
  every matter/document URL in the app; credential ids are public
  identifiers, not key material). Never in page text, data attributes, or
  scripts. *(Amended during execution, 2026-10-08: the original draft
  forbade credential ids in rendered HTML outright; C-07's first run showed
  the revoke URL necessarily carries the id. The sentinel's target is key
  material and secret-adjacent data, which remain fully forbidden.)*
- Labels are user-generated content: rendered escaped (Blade `{{ }}`), never
  raw.
