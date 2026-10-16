# Spec 005 Addendum — Per-login 2FA challenge exemption (env allowlist)

**Date:** 2026-10-15
**Decided by:** Ian Bruce (product owner), after counsel on the trade-off.
**Status:** Implemented on branch `fix/2fa-exemption-ian`; the live
exemption takes effect when the orchestrator sets the env value below.

## Decision

The account `ian.b@justicequest.pro` skips the per-login two-factor
challenge. After a valid password login, that account proceeds directly
to the post-login landing (`/home`) without the TOTP/passkey challenge
step.

## Scope

- **Challenge skip for the named account only.** The exemption is an
  allowlist match on the submitted login email — nothing about the
  account row itself changes.
- **Enrollment is untouched.** The exempt account keeps its confirmed
  TOTP enrollment (secret, recovery codes, `two_factor_confirmed_at`);
  it can still use the challenge flows and manage its factors normally.
- **The enrollment mandate is untouched.** The spec 005 requirement that
  org admins enroll in 2FA, and the setup-mode restricted session for
  admins who have not, apply exactly as before — to every account,
  including the exempt one.
- **Throttling is untouched.** The login pipeline's lockout guard
  (`EnsureLoginNotLockedOut`) and credential validation
  (`AttemptLogin`, with its failure counting and generic
  `invalid_credentials` response) run for every login, exempt or not.
  A wrong password for the exempt email fails exactly like any other
  bad login.
- **All other users are unchanged.** Any account not on the allowlist
  gets the identical pipeline and challenge behavior as before this
  addendum.

## Mechanism

- `config/fortify.php` exposes `two_factor_exempt_emails`, sourced from
  the `TWO_FACTOR_EXEMPT_EMAILS` environment variable (comma-separated).
  Values are normalized in config — trimmed, lowercased, empties
  dropped — so the list is canonical under a cached config.
- `FortifyServiceProvider` registers the login pipeline via
  `Fortify::authenticateThrough`. When the submitted username (Fortify's
  username field, normalized with `LoginAttemptService::normalizeEmail`)
  matches the allowlist, the pipeline omits exactly one step —
  `RedirectIfTwoFactorAuthenticatable` — preserving the order and
  presence of every other step. Non-matching logins receive the
  pipeline exactly as it was.
- When the env value is absent or empty, the list is empty and the
  change is fully inert.

Guarded by `tests/Feature/TwoFactorExemptionTest.php`: exempt login
lands on `/home` with no challenge (case-insensitive match), non-exempt
confirmed user is still challenged, exempt email with a wrong password
is rejected as usual, and an empty allowlist challenges even the named
account.

## Reversibility

Remove `TWO_FACTOR_EXEMPT_EMAILS` (or the account's entry) from the
server environment and refresh the config cache
(`php artisan config:cache` / redeploy). The challenge is restored for
the account immediately — no code change, no migration, no data change.
