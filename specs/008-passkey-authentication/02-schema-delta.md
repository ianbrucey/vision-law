# 008 — Passkey authentication (WebAuthn) — Schema delta

**Status:** APPROVED (with the brief, 2026-10-08)
**Author:** Lotus (build worker)
**Date:** 2026-10-08

---

## Delta: one new table, no changes to existing tables

Passkey credentials live in their own table (published from Laragear WebAuthn
v5, migration renamed into the 008 sequence). Credentials are **user-owned** —
tenancy flows from the owning user, exactly like the `two_factor_*` columns on
`users` — so the table carries no `org_id` / `matter_id` keys (it is not a
matter-owned table).

### `webauthn_credentials` (NEW)

| Column | Type | Notes |
|---|---|---|
| `id` | varchar(510) PRIMARY KEY | The WebAuthn credential ID (base64url), assigned by the authenticator — not a UUID |
| `authenticatable_type` / `authenticatable_id` | morphs (uuid) | Owning user (`App\Models\User`) |
| `user_id` | uuid | The WebAuthn **user handle** — a random UUID the package assigns per user to anonymize them to authenticators; it is NOT our user PK and is copied across the user's credentials |
| `alias` | varchar nullable | User-chosen label (008-D10) — the only credential data rendered or audited |
| `counter` | bigint unsigned nullable | Signature counter; the validator rejects non-increasing counters (clone detection) and syncs it on each assertion |
| `rp_id` | varchar | RP ID the credential was created for (env-configured, 008-D03) |
| `origin` | varchar | Origin recorded at registration |
| `transports` | json nullable | Authenticator transports (`internal`, `usb`, …) |
| `aaguid` | uuid nullable | Authenticator model GUID — never rendered (008-D10) |
| `public_key` | text | COSE **public** key, stored through the model's `encrypted` cast (encrypted at rest by the package — defense in depth; the value is not secret). The matching private key never leaves the authenticator (C-07) |
| `attestation_format` | varchar, default `none` | No attestation enforcement in v1 (008-D04) |
| `certificates` | json nullable | Attestation certificate chain when a format provides one; empty for `none` |
| `disabled_at` | timestamp nullable | Laragear's soft-state. NOT used by 008 flows — revocation deletes the row (008-D08); the column ships with the package schema and stays null |
| `created_at` / `updated_at` | timestamps | `updated_at` advances on counter sync — displayed as "last used" |

Indexes: morphs index `webauthn_user_index` on
(`authenticatable_type`, `authenticatable_id`).

**Migration provenance (amended during execution, 2026-10-08):** the file is
hand-rolled rather than deferring to `WebAuthnCredential::migration()` — the
package's `createMorph()` emits a **bigint** `authenticatable_id` (it assumes
integer user keys), which cannot hold Vision Law's UUID user keys (spec 001).
The hand-rolled migration copies the package's `makeMigration()` closure
column-for-column, substituting `uuidMorphs('authenticatable',
'webauthn_user_index')` for the morph only.

### Existing tables

No changes. In particular:

- `users.two_factor_recovery_codes` is **reused, not extended**: 008-D02 issues
  codes into the same encrypted column when it is null at first-passkey time.
  No new column records "codes came from a passkey" — the audit event
  `mfa.passkey.recovery_codes.issued` is the record.
- No `passkey_confirmed_at` or equivalent: a stored credential row **is** the
  confirmation (the attestation ceremony proves possession at registration).

## Domain-model fold-back (Freeze)

Add `webauthn_credentials` to `docs/DOMAIN_MODEL.md` under the user/auth
cluster with the note: *user-owned WebAuthn public-key credentials (spec 008);
private keys and biometric data never exist server-side.*
