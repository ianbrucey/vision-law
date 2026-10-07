# 005 — Two-factor authentication screens — Schema delta

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## Delta: none

The `users` table already carries everything 2FA needs (verified against the live database 2026-10-07):

| Column | Type | Notes |
|---|---|---|
| `two_factor_secret` | text (encrypted by Fortify's own actions; in `$hidden`) | Plaintext never leaves the encrypted column except to the owning user via the QR route |
| `two_factor_recovery_codes` | text (encrypted by Fortify's own actions; in `$hidden`) | 8 single-use codes per 001 C-06 |
| `two_factor_confirmed_at` | timestamp nullable (`datetime` cast) | Null until the user confirms with a valid code |

No migration. No domain-model fold-back at Freeze.
