# 004 — Invitation onboarding UI — Verdict evidence (C-01–C-08)

**Date:** 2026-10-07
**Branch:** `feat/004-invitation-onboarding`
**Full gate run (freeze):** `php artisan test` → **141 passed (1345 assertions)**; `vendor/bin/pint --test` → 116 files PASS; `vendor/bin/phpstan analyse` → no errors; `npm run build` → built OK.
**Reconciled freeze deltas:** no migration created (invitations table pre-existed, migration `2026_10_07_020004_create_invitations_table.php`); no new `<x-ui.*>` primitives (all 11 used are in the 002 inventory); `docs/UI_Standards.md` untouched.

Per-claim verdict tests (each run individually at freeze; all PASS):

| Claim | Test | Assertions | Statement |
|---|---|---|---|
| C-01 | `test_admin_invitations_index_lists_only_org_invitations` (`tests/Feature/AdminInvitationsUiTest.php`) | 5 | Org admin sees the org's invitations in a table; a second org's invitations are absent |
| C-02 | `test_admin_creates_invitation_and_receives_accept_link` (`tests/Feature/AdminInvitationsUiTest.php`) | 11 | POST `admin/invitations` creates the row; the accept URL with the plaintext token appears exactly once |
| C-03 | `test_admin_revokes_pending_invitation_from_ui` (`tests/Feature/AdminInvitationsUiTest.php`) | 9 | DELETE sets `revoked_at`; row shows "Revoked"; accepted invitations cannot be revoked (422) |
| C-04 | `test_non_admin_cannot_access_invitation_admin_ui` (`tests/Feature/AdminInvitationsUiTest.php`) | 13 | attorney/viewer/anonymous get the existing denial semantics on all three admin routes |
| C-05 | `test_invitee_accepts_invitation_through_ui_new_user` (`tests/Feature/InvitationAcceptUiTest.php`) | 17 | New user registers bound to the token: `accepted_at` set, role assigned, matter grant created when scoped |
| C-05 | `test_existing_user_accepts_invitation_through_ui` (`tests/Feature/InvitationAcceptUiTest.php`) | 4 | Existing user accepts through the UI path |
| C-05 | `test_existing_user_round_trip_sign_in_then_accept` (`tests/Feature/InvitationAcceptUiTest.php`) | 13 | Sign-in via the accept page's next link lands back on the accept page with a working Accept button (this test exposed the guest-middleware round-trip break; fixed under 004-D08) |
| C-06 | `test_invalid_token_shows_safe_not_found_page` (`tests/Feature/InvitationAcceptUiTest.php`) | 13 | Invalid, expired, revoked, and already-accepted tokens all render the identical generic 404 page |
| C-07 | `test_token_hash_never_leaks_in_ui` (`tests/Architecture/LeakSentinelTest.php`) | 13 | `token_hash` never appears in rendered admin/accept HTML or logs |
| C-07 | `test_admin_invitation_token_shows_only_in_one_time_banner` (`tests/Architecture/LeakSentinelTest.php`) | 14 | Plaintext token appears exactly once (the one-time copy-paste banner); amended under 004-D07 to encode the valid-holder exception on the accept page |
| C-08 | `tests/Architecture/VisionUiTest.php` (all 15 doors on the new views) | 22 | Buttons, form controls, page titles, modals, light-only — doors hold; no new `<x-ui.*>` components beyond the 002 inventory |

**Conclusion:** all 8 verdicts green.
