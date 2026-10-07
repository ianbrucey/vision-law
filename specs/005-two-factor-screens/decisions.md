# 005 — Two-factor authentication screens — Decision log

> One home per fact. Formal decisions live here; the dev journal links here rather than duplicating.

---

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-07 | 005-D01 | Org admin without confirmed 2FA gets a restricted "setup-mode" session (flag `visionlaw.2fa_setup_required`) instead of logout+403; a middleware allowlist limits the session to the enrollment routes until 2FA is confirmed | The current denial makes enrollment unreachable — a deadlock Ian hit live: he cannot log in without 2FA and cannot enroll without logging in | Keep logout+403 and enroll out-of-band (not shippable); full-access grace period (weakens the MFA-required policy) | The MFA-required policy is preserved and becomes completable; the setup-mode state needs airtight allowlist tests | [D] |
| 2026-10-07 | 005-D02 | Enrollment page at `GET user/two-factor` (name `two-factor.settings`); challenge page at `GET two-factor-challenge` named `two-factor.login` | Both GET views are missing (`fortify.views => false`); Fortify's post-password redirect targets the `two-factor.login` route name, which currently 404s | Fortify default paths under a prefix; separate settings area (no settings page exists yet) | Minimal new surface; the redirect chain works end to end | [D] |
| 2026-10-07 | 005-D03 | Recovery codes render exactly once after confirm/regenerate, on the enrollment page; never re-displayed, never emailed | Mail is unconfigured and re-display would weaken the backup-code model | Re-display on demand (weakens single-use hygiene); email delivery (unconfigured transport) | The UI must say openly that closing the page loses the codes; regeneration is one click behind password confirmation | [D] |
| 2026-10-07 | 005-D04 | The QR code is embedded via `<img src="…two-factor.qr-code">`, never inline SVG and never the secret-key route output in page source | Keeps the plaintext secret and otpauth URIs out of HTML source entirely | Inline SVG (secret-adjacent bytes in source); manual-key-only (worse UX) | Leak-sentinel test asserts absence from page source | [D] |
| 2026-10-07 | 005-D05 | Challenge failures use one generic message for bad TOTP, bad recovery code, and consumed recovery code | Matches the existing `invalid_credentials` no-enumeration tone of the login pipeline | Distinct messages (would let an attacker probe which factor failed) | Slightly less helpful error copy; deliberate | [D] |
