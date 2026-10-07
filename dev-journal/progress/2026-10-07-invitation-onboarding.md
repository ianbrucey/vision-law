# Invitation onboarding UI (spec 004) — 2026-10-07

> Progress entry for the spec 004 build (tickets T-01…T-05) on
> `feat/004-invitation-onboarding`. Decisions live in
> `specs/004-invitation-onboarding/decisions.md` — linked, not duplicated.
> Verdicts in `specs/004-invitation-onboarding/07-evidence/C-01-C-08-verdicts.md`;
> retrospective in `specs/004-invitation-onboarding/08-retrospective.md`.

## Done

- **T-01** token-handoff response layer: `InvitationService::inviteWithToken()`
  returns an `InvitationWithToken` DTO; `invite()` delegates unchanged —
  validation, transaction, audit, and mail keep one home (004-D06). API/JSON
  shapes unchanged; web requests get Blade + redirects. (C-01 part)
- **T-02** admin invitations UI: `admin/invitations` index table (email, role,
  matter scope, status, expiry, created), create form with matter-scope select,
  one-time accept-link banner, revoke with danger + consequence-line pattern,
  "email not configured" banner (004-D03). (C-01, C-02, C-03)
- **T-03** invitee accept UI: `/invitations/{token}` accept page (org name, role
  chip, matter name, expiry), new-user register bound to the token, existing
  users sign in via a next link. (C-05, C-06)
- **T-04** authz/round-trip: all three admin routes behind auth +
  `RequireOrgAdmin` with org scoping; leak sentinels extended to the new
  surfaces; the signed-in-user round trip fixed — accept page is reachable by
  signed-in users, landing back with a working Accept button (004-D08).
  (C-04, C-07)
- **T-05** freeze: no schema delta (invitations table pre-existed, no
  migration), no new `<x-ui.*>` primitives (11 existing ones only, all in the
  002 inventory), `UI_Standards.md` untouched, decisions D01–D08 all recorded
  and marked `[D]` (D08 row committed at freeze), verdict evidence written,
  retrospective written, merged to main.

## Bugs found by tests

- The guest-middleware round-trip break: after sign-in via the accept page's
  next link, the user was bounced away from the accept page — caught by
  `test_existing_user_round_trip_sign_in_then_accept`, fixed under 004-D08.
- The leak sentinel for the one-time banner initially failed to encode the
  valid-holder exception; amended under 004-D07.

## Final gates

- `php artisan test`: 141 passed / 1345 assertions
- `vendor/bin/pint --test`: 116 files PASS
- `vendor/bin/phpstan analyse`: no errors
- `npm run build`: OK
- Merged: `git merge --no-ff feat/004-invitation-onboarding` → 262ec85 on main, pushed;
  origin/main matches local HEAD.

## Carry-forward (matter model)

See `08-retrospective.md`: record decisions at decision time; contracts
enumerate actor states per route; keep verdict tests journey-shaped; plan the
schema fold-back as a named ticket step; seed leak sentinels from T-01;
resolve mail transport or carry the banner pattern deliberately.
