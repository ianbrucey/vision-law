# 010 — App home & authenticated shell — Strategic brief

**Status:** APPROVED — Ian Bruce, 2026-10-15
**Author:** Lotus (orchestrator)
**Date:** 2026-10-15
**Origin:** Ian's first real login (2026-10-15). After 2FA enrollment he landed on the public landing page with no navigation into the app and no way to sign out. Verification against the live codebase confirmed the report and found the root cause (see Archaeology seed). This is the "app home" spec that decision 003-D02 explicitly deferred to: *"until the app home ships in its own spec, logins and logouts land on the public landing page."*

---

## Goal

An authenticated user always has a home and a way around: login lands on an app home (`/home`) that answers "what's mine and what happened lately," every app page wears the same chrome (primary nav + user menu with sign-out), the public landing acknowledges signed-in users instead of trapping them, and signing out is reachable from everywhere in the app.

## Archaeology seed (verified 2026-10-15 against main `6b90a6a`)

- Spec 002 built the shell *frame*: `layouts/app.blade.php` has a fixed top bar with **slots** for `nav`, `org`, and `userMenu` — but they are slots each page must furnish, and no standard furnishing was ever built. **No Blade view anywhere contains a sign-out control** (every `logout` match in the codebase is backend PHP: SessionController, listeners, middleware). Pages render with inconsistent or empty chrome.
- The public landing (`resources/views/public/landing.blade.php`, layouts/public) has zero auth-awareness: no `auth()` check, no app links, no sign-out. Fortify `redirects.login = '/'` (003-D02) drops authenticated users onto it.
- Fortify's `home` config already points at `/home`; no `/home` route exists.
- The 006 activity read model (over `audit_events`) and the matters index already provide every datum the home needs. No new domain data is required.

## Claims

| ID | Capability | Verdict (named binary test) | Anchor |
|---|---|---|---|
| C-01 | `/home` exists as the authenticated app home: guests hitting it are redirected to `/login`; any authenticated user (2FA complete) gets a 200 dashboard | `test_home_requires_auth_and_renders_dashboard` — guest → 302 `/login`; authenticated user → 200 with dashboard markers | 003-D02 (this spec is the deferred app-home spec) |
| C-02 | Login lands on `/home`: the Fortify login redirect changes from `/` to `/home`; logout still lands on the public `/`; registration and password-reset redirects are unchanged | `test_login_redirects_to_home_logout_to_landing` — full login flow ends at `/home`; logout ends at `/`; 003 auth-page tests unchanged and green | 003-D02 superseded in this one respect |
| C-03 | Dashboard v1 is built only from data that exists today: (a) my matters — matters the user can access, most recently updated first, with a count and a link to the full index; (b) recent activity — the 006 audit read model scoped to what the user can access; (c) quick actions appropriate to role (New matter; org admins also see Invitations); (d) a security status card (2FA confirmed state, passkey count, link to the security page) | `test_home_shows_matters_activity_actions_and_security_status` — fixture user with 2 matters + activity + 1 passkey sees exactly those, correctly ordered, with role-correct actions | 006 read model; 005/008 status surfaces |
| C-04 | Empty states are guided, not blank: a user whose org has no matters sees a first-run home that explains the product in one line and offers the primary action (create a matter / accept an invitation path), never an empty white page or a fake widget | `test_home_empty_state_guides_first_run` — zero-matter user → guided empty state present; no placeholder/deadline widgets rendered | — (009 supplies the deadlines module later) |
| C-05 | The chrome becomes the layout's default, not a per-page slot: `layouts/app` renders the standard primary nav (Home, Matters, Document search, Templates, plus role-gated admin entries: Invitations, Retention) and a user menu (user name + org, Security settings, Sessions, **Sign out**) on every page that uses the layout, without each page passing slots; a page may still extend the nav | `test_app_chrome_renders_nav_and_user_menu_on_all_app_pages` — matters index, matter show, document search, templates, security page, and invitations each render the nav, the user menu, and a working sign-out form | 002 shell (frame exists; this furnishes it) |
| C-06 | Sign out works from everywhere and really ends the session: submitting sign-out from any app page ends the session (subsequent `/home` request redirects to `/login`) and lands on the public `/` | `test_sign_out_from_any_app_page_ends_session` — sign out from the matter page → session invalidated; `/home` → 302 `/login`; landing shows the guest header | SessionController/Fortify logout route |
| C-07 | The public landing is auth-aware: a signed-in visitor sees an "Open app" action (→ `/home`) and Sign out in the header instead of Sign in / Register; a guest sees the page exactly as spec 003 shipped it | `test_landing_header_reflects_auth_state` — authenticated → Open app + sign-out present, sign-in CTA absent; guest → identical to the 003 baseline assertions | 003 regression boundary |
| C-08 | Setup mode is untouched: a session in 2FA setup mode (005-D01) requesting `/home` or following the login redirect is still confined to the setup allowlist (`/home` is NOT added to it); once 2FA is confirmed and the flag clears, the same user reaches `/home` normally | `test_setup_mode_still_confined_until_2fa_confirmed` — flagged session → setup redirect on `/home`; after confirmation → 200 | 005-D01 (regression claim; 005 suite unmodified) |
| C-09 | Nothing leaks through the home: matters, counts, and activity on `/home` are scoped to the user's matter access (006 grants) — a user sees zero trace of matters they cannot access, including in counts | `test_home_scopes_to_matter_access` — two-org fixture: user sees only their org's matters/activity; the other org's matter title and count contribution are absent | 006 authorization law |
| C-10 | Mobile gate (standing law): at 390px the chrome collapses into the 002 drawer with every nav item and sign-out reachable, dashboard modules stack in priority order (matters → activity → security), no horizontal overflow, all tap targets ≥44px | `test_mobile_home_and_chrome_gate` — the home + drawer join the mobile-gate list under the 007 technique (real headless Chromium, CI-portable) | 007-D04 |

## Blueprint anchors

- [D] 003-D02 is superseded by this spec for the login redirect only, exactly as its own text anticipated ("until the app home ships in its own spec"). Logout → `/` and all other 003 redirects stand.
- [D] 002-D02 UI standards: `<x-ui.*>` components only, light app surfaces, the existing `layouts/app` frame and drawer — this spec furnishes the frame, it does not redesign it.
- [D] 006 authorization scopes every datum the home shows (C-09). The home introduces no new access path.
- [D] 005 setup-mode allowlist is not widened (C-08).
- [D] 007-D04 mobile-first gate (C-10).
- [P] Path and name: `/home`, route name `home` — matches the existing Fortify `home` config value.
- [P] Dashboard v1 modules are matters / activity / quick actions / security status. **No placeholder widgets** for future features: spec 009's deadlines module joins the home when 009 ships (its brief already proposes the deadlines view as the post-login landing; with 010 in place that becomes a module on this home, or the top module — Council reconciles when 009 reaches Plan).
- [P] Ordering note: 010 is small and unblocks actual use of everything already shipped; the orchestrator recommends running it **before** spec 009 execution (009's brief remains DRAFT awaiting approval either way; approving 010 does not approve 009).
- [O] **O-01 `/` for the signed-in user:** stay on the landing with the auth-aware header (C-07, proposed), or 302 straight to `/home`? Proposed: stay — the landing remains the shareable public face, and the header makes the way in obvious. Ian's call.
- [O] **O-02 Sessions page linkage:** the user menu links the existing sessions page (SessionController index). If that page's chrome predates this spec it gets the default chrome like everything else (C-05) — no separate work item expected; Archaeology confirms.

## Security classification

- **Actors:** guest (landing guest state, `/home` denied), authenticated user (own home), org admin (extra quick actions + admin nav entries), setup-mode session (confined per 005), user with no matter access (guided empty state, zero data).
- **Data classes touched:** confidential (matter titles/activity — access-scoped per C-09), internal (own security status summary).
- **Allow events (audit):** none new — the home is read-only; existing login/logout audit stands.
- **Denial events (audit):** none new.
- **Leak sentinels:** no cross-org matter titles, counts, or activity on `/home`; the auth-aware landing header reveals nothing beyond the visitor's own sign-in state.

## Evidence and fixtures

Fixtures: a two-org graph (org A with matters + activity, org B disjoint), a user with matters + confirmed 2FA + one passkey, an org admin, a zero-matter user, and a setup-mode-flagged session. All synthetic; no production data. The guest-landing baseline is spec 003's own test assertions, unchanged.

## Pre-mortem

- **Likely failure:** furnishing the layout's chrome breaks a page family that assumed the bare frame (documents/retention admin pages are the suspects). Mitigated by C-05's verdict enumerating the page families explicitly, plus the full suite.
- **Likely failure:** the login redirect change loops for setup-mode users (login → `/home` → setup redirect → …). Mitigated by C-08's verdict covering the flagged-session path directly.
- **Assumption:** the 006 activity read model can be scoped per-user cheaply. Archaeology verifies the query shape; if it needs a scope helper, that's an in-spec refactor, not new behavior.
- **Unknown:** whether any page currently passes its own `nav`/`userMenu` slots that the default chrome would duplicate. Archaeology inventories slot usage; the layout default must yield to a page-provided slot.

## Non-goals

- Deadlines, agenda, tasks, reminders, or any calendaring widget (spec 009).
- A notifications inbox (spec 009's reminder service).
- New search surfaces (the chrome links the existing 007 document search; it does not rebuild it).
- Profile editing, avatar uploads, org switching UI (single-org membership is the current reality; the org slot shows the org name only).
- Any visual redesign of the 002 shell or the 003 landing beyond the auth-aware header.
- Dark mode, theming, native apps.
