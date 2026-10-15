# 010 — Freeze

**Spec:** 010 — App home & authenticated shell
**Branch:** `feat/010-app-home` · **Head:** `a19a0f2` (C-02 resolution; feature at `dec92b3`, earlier freeze at `1b3fb53`) (+ this freeze commit)
**Date:** 2026-10-15 · **Status:** FROZEN — all 10 claims GREEN (C-02 resolved 2026-10-15, 010-D03 resolution)

## Per-claim verdicts

| Claim | Verdict | Named test | Notes |
|---|---|---|---|
| C-01 — /home is the authenticated app home | ✅ GREEN | `test_home_requires_auth_and_renders_dashboard` | Guests 302 → /login; authed users get the 200 dashboard. |
| C-02 — Login lands on /home | ✅ GREEN | `test_login_redirects_to_home_logout_to_landing` (un-skipped, full flow) | Resolved by the product owner 2026-10-15 (010-D03 resolution, fork (a)): 010 supersedes 003-D02 for the login redirect; `config/fortify.php` `redirects.login` flipped to `/home`. The test covers both doors — plain login → `/home`, and the confirmed-TOTP flow (credentials → challenge → `/home`) — plus logout → `/` after each. The two pinning assertions in the 003/005 suites were re-pinned under the recorded exception, and three 008 challenge-completion assertions followed the same config value (010-D11). |
| C-03 — Dashboard v1 from existing data | ✅ GREEN | `test_home_shows_matters_activity_actions_and_security_status` | Matters (scoped, updated-desc, count, index link), activity (006 read model, same scope), quick actions (New matter; Invitations for org admins), security card (2FA confirmed state, 1 passkey, settings link). Non-admin asserted to lack the Invitations action. |
| C-04 — Guided empty state | ✅ GREEN | `test_home_empty_state_guides_first_run` | Zero-matter org → guidance + Create a matter (+ Invite your team for org admins); dashboard modules absent; no placeholder/deadline widgets. |
| C-05 — Chrome becomes the layout default | ✅ GREEN | `test_app_chrome_renders_nav_and_user_menu_on_all_app_pages` | Verified on home, matters index, matter show, templates, security page, invitations (the six real app pages). Two documented deviations: no "Sessions" menu entry (010-D02 — no sessions page exists) and no "Document search" nav entry / enumeration (010-D09 — its route is a JSON-only API). Role-gated entries asserted absent for non-admins. |
| C-06 — Sign out from any app page | ✅ GREEN | `test_sign_out_from_any_app_page_ends_session` | Sign-out form present across pages; POST ends the session, lands on `/`, /home then 302s to /login. |
| C-07 — Landing is auth-aware | ✅ GREEN | `test_landing_header_reflects_auth_state` | Guest header is the 003 header byte-for-byte in behavior; signed-in header (scoped to the header `<nav>`) shows Open app → /home + Sign out form, no Sign in. O-01 resolved as proposed (010-D01). |
| C-08 — Setup mode untouched | ✅ GREEN | `test_setup_mode_still_confined_until_2fa_confirmed` | `home` was NOT added to the 005 allowlist; flagged session → 302 to enrollment; flag cleared → 200. |
| C-09 — Home leaks nothing | ✅ GREEN | `test_home_scopes_to_matter_access` | Cross-org matter (the most recently updated overall) contributes no title, number, count (2, not 3), or activity (3 rows, not 4); grant-less in-org viewer sees none of the org's matters; other org's admin sees exactly their own. |
| C-10 — Mobile gate | ✅ GREEN (CI simulator) | `test_mobile_home_and_chrome_gate` | 390px, real headless Chrome: no overflow, no sub-44px primary actions, no loose tables on home / home-empty / matters; drawer markup carries all nav targets + sign-out; modules stack matters → activity → security. On the dev host the test skips gracefully (snap Chromium cannot read sys-temp staging — see journal). Screenshots: `10-mobile-home.png`, `10-mobile-home-empty.png`, `10-mobile-matters.png`. |

## Quality gates

| Gate | Result |
|---|---|
| Pint (`vendor/bin/pint --test`) | PASS — 279 files |
| PHPStan level 7 | OK — no errors, no baseline (none exists) |
| Full suite — dev host | 247 passed / 0 failed / 1 skipped / 4099 assertions (the only skip is C-10's host-environment skip; C-02 runs and passes) |
| Full suite — CI simulator (GitHub-runner recipe) | **248 passed / 0 failed / 0 errors / 0 skipped (4137 assertions)** — C-02 un-skipped and green, incl. the full TOTP flow; C-10 passed under real headless Chrome |
| Spec 005 suite | Green — one login-redirect assertion re-pinned under the recorded 010-D03 exception; nothing else touched |
| Spec 003 suite (`PublicSiteAuthTest`) | Green — the login assertion re-pinned under 010's supersession of 003-D02; the guest-page redirect pin is preserved via 010-D10 |

Baseline comparison: main-lineage suite was 238 tests / 3925 assertions
(sim-verified). Spec 010 adds 10 tests (9 in `AppHomeTest` incl. C-02,
1 in `AppHomeMobileTest`). The 2 host-baseline failures
(`RegistrationFlagTest`) were the host's stale route cache, not code;
cleared before these runs.

## Decisions recorded beyond the brief

010-D01 (O-01 as proposed), 010-D02 (Sessions entry omitted — no page),
010-D03 (C-02 resolved 2026-10-15 — login lands on `/home`, fork (a)
decided by Ian Bruce), 010-D04 (chrome in layout + components),
010-D05 (home-local activity describer), 010-D06 (fixtures canonical in
04-fixtures.json), 010-D07 (no schema changes; the one config value
changed is `redirects.login`, under the 010-D03 resolution), 010-D08 [P]
(009 module joins later), 010-D09 (Document search omitted — JSON-only
API), 010-D10 (guest-page redirect pinned in FortifyServiceProvider;
route named `app.home`), 010-D11 (the same `redirects.login` value
governs two-factor/passkey challenge completions; three 008 assertions
re-pinned). Full text: `02-council.md` and `decisions.md`.

## Protected files

Untouched: all 006/007 surfaces; `specs/009-calendaring/**`; the live
`vision_law` database (tests ran only against `vision_law_test`).
Touched under recorded authorization only, assertion/value-only:
`tests/Feature/TwoFactorScreensTest.php` (one login-redirect assertion,
010-D03 exception), `tests/Feature/PublicSiteAuthTest.php` (one
login-redirect assertion, 010 superseding 003-D02),
`tests/Feature/PasskeyChallengeTest.php` (three challenge-completion
assertions, 010-D11), `config/fortify.php` (`redirects.login` only, plus
its comment block).

## What remains

- Nothing open in this spec — all 10 claims GREEN. Merge-ready.
- Post-merge, the poller deploys from main per the standing pipeline; the
  live app gains /home, the chrome, the auth-aware header, and
  login → /home with no migration (schema delta: none).
