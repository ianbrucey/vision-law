# 010 — Freeze

**Spec:** 010 — App home & authenticated shell
**Branch:** `feat/010-app-home` · **Head:** `dec92b3` (+ this freeze commit)
**Date:** 2026-10-15 · **Status:** FROZEN with one BLOCKED claim (C-02)

## Per-claim verdicts

| Claim | Verdict | Named test | Notes |
|---|---|---|---|
| C-01 — /home is the authenticated app home | ✅ GREEN | `test_home_requires_auth_and_renders_dashboard` | Guests 302 → /login; authed users get the 200 dashboard. |
| C-02 — Login lands on /home | ⛔ **BLOCKED** | `test_login_redirects_to_home_logout_to_landing` (delivered, skipped-with-reason) | 010-CONFLICT-01 / 010-D03: the brief simultaneously orders the redirect change and the 003 tests "unchanged and green", but `PublicSiteAuthTest` and the unmodifiable 005 suite both pin `POST /login → /`. Needs the product owner's decision: (a) authorize updating the two assertions under 010's supersession of 003-D02 (with an explicit exception for the 005 file), then flip `redirects.login` and un-skip the test; or (b) amend C-02 to retire the redirect change. Logout → `/` half of the claim is already true and is covered green by C-06's test. |
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
| Full suite — dev host | 246 passed / 0 failed / 2 skipped / 4081 assertions (skips: C-02 blocked-by-design; C-10 host-environment skip) |
| Full suite — CI simulator (GitHub-runner recipe) | **247 passed / 0 failed / 0 errors / 1 skipped (4119 assertions)** — the only skip is C-02; C-10 ran and passed under real headless Chrome |
| Spec 005 suite | Green, **unmodified** |
| Spec 003 suite (`PublicSiteAuthTest`) | Green, **unmodified** — including the guest-page redirect pin, preserved via 010-D10 |

Baseline comparison: main-lineage suite was 238 tests / 3925 assertions
(sim-verified). Spec 010 adds 10 tests (9 in `AppHomeTest` incl. the
skipped C-02, 1 in `AppHomeMobileTest`). The 2 host-baseline failures
(`RegistrationFlagTest`) were the host's stale route cache, not code;
cleared before these runs.

## Decisions recorded beyond the brief

010-D01 (O-01 as proposed), 010-D02 (Sessions entry omitted — no page),
010-D03 (C-02 blocked), 010-D04 (chrome in layout + components),
010-D05 (home-local activity describer), 010-D06 (fixtures canonical in
04-fixtures.json), 010-D07 (no schema/config changes), 010-D08 [P] (009
module joins later), 010-D09 (Document search omitted — JSON-only API),
010-D10 (guest-page redirect pinned in FortifyServiceProvider; route named
`app.home`). Full text: `02-council.md` and `decisions.md`.

## Protected files — untouched

`tests/Feature/TwoFactorScreensTest.php` and all spec 005 tests;
`config/fortify.php` values; 006/007/008 surfaces; `specs/009-calendaring/**`;
the live `vision_law` database (tests ran only against `vision_law_test`).

## What remains

- **C-02 decision** (010-D03's fork) — the only open item. Everything else
  is merge-ready.
- Post-merge, the poller deploys from main per the standing pipeline; the
  live app gains /home, the chrome, and the auth-aware header with no
  migration (schema delta: none).
