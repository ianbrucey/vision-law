# 010 — Plan

**Spec:** 010 — App home & authenticated shell
**Inputs:** `00-brief.md`, `01-archaeology.md`, `02-council.md`, `04-fixtures.json`
**Rule:** implementation order is backend-out; each ticket's acceptance is its
named test(s) from `00-brief.md` §4. No ticket edits a protected file
(archaeology §Protected files). C-02 is excluded from execution per
010-D03 (BLOCKED) — its ticket is the documentation trail only.

## T-01 — Fixtures (before any logic)

- **Files:** `specs/010-app-home/04-fixtures.json` (canonical definition),
  `tests/Helpers/FixtureLoader.php` (EXTEND: `loadHomeFixtures()` + runner
  supporting `organization` / `user` / `matter` / `grant` via the existing
  006 handlers, plus `two_factor`, `passkey`, `audit_event`; `audit_event`
  logs through `AuditLogger::log` at stepped test-now offsets so ordering is
  deterministic; matter `updated_at` offsets applied post-create).
- **Graph:** 3 orgs (Acme with 2 matters + grants + activity; Other with 1
  matter + its own activity; Empty with none), 6 users (Acme org_admin with
  confirmed 2FA + 1 passkey; Acme attorney with 2 grants; Acme viewer with
  none; Other org_admin; Empty-org attorney + Empty-org org_admin).
- **Acceptance:** a bootstrap assertion inside `AppHomeTest` setUp-path tests
  (the loader runs in every claim test; C-03/C-09 assertions pin the graph's
  observable consequences — counts, titles, scoping).

## T-02 — Home route, controller, view (C-01, C-03, C-04, C-09)

- **Files:** `routes/web.php` (add `GET /home` name `home` in the auth
  group), `app/Http/Controllers/HomeController.php` (NEW),
  `resources/views/home.blade.php` (NEW).
- **Build:** controller assembles matters (accessible scope, updated desc,
  count), activity (accessible-matter subquery, 006 ordering, actor+matter
  eager loads, cap 10), quick actions (New matter; org admins also
  Invitations), security status (2FA confirmed state, enabled passkey
  count). View uses `x-ui.page-header`, `x-ui.card`, `x-ui.stat`,
  `x-ui.empty`, `x-ui.button` only; module order: matters → activity →
  security; org-empty → guided empty state instead of modules.
- **Acceptance:** `test_home_requires_auth_and_renders_dashboard` (C-01),
  `test_home_shows_matters_activity_actions_and_security_status` (C-03),
  `test_home_empty_state_guides_first_run` (C-04),
  `test_home_scopes_to_matter_access` (C-09).

## T-03 — Chrome defaults (C-05, C-06)

- **Files:** `resources/views/layouts/app.blade.php` (EXTEND — defaults
  behind the existing `@isset` slot checks),
  `resources/views/components/app/nav.blade.php` (NEW),
  `resources/views/components/app/user-menu.blade.php` (NEW).
- **Build:** default nav (Home, Matters, Templates;
  org_admin also Invitations, Retention — "Document search" omitted per
  010-D09: its route is a JSON-only API with no page); default org label = user's org;
  user menu (desktop disclosure + drawer user block): name + org, Security
  settings, Sign out form (POST `logout`, CSRF). Menu composition per
  010-D02 (no Sessions entry — no page exists).
- **Acceptance:** `test_app_chrome_renders_nav_and_user_menu_on_all_app_pages`
  (C-05, enumerating matters index, matter show, document search,
  templates, security page, invitations),
  `test_sign_out_from_any_app_page_ends_session` (C-06).

## T-04 — Auth-aware landing header (C-07)

- **Files:** `resources/views/public/partials/nav.blade.php` (EXTEND —
  `@auth` branch only; guest markup unchanged).
- **Acceptance:** `test_landing_header_reflects_auth_state` (C-07); the 003
  suite (`PublicSiteAuthTest`) stays green unmodified.

## T-05 — Setup-mode regression pin (C-08)

- **Files:** test only (in `AppHomeTest`). No allowlist change — `home`
  must NOT be added to `RestrictToTwoFactorSetup`.
- **Build:** flagged session → GET /home → 302 two-factor settings;
  confirm 2FA (flag clears) → GET /home → 200.
- **Acceptance:** `test_setup_mode_still_confined_until_2fa_confirmed` (C-08);
  full 005 suite green unmodified.

## T-06 — Mobile gate (C-10)

- **Files:** `tests/Feature/AppHomeMobileTest.php` (NEW; 007
  DocumentMobileTest technique; CHROMIUM_BIN env with graceful skip;
  staging in `sys_get_temp_dir()` only; screenshots copied to
  `specs/010-app-home/10-mobile-*.png`).
- **Screens:** `/home` (with data), `/home` (empty org), `/matters` —
  390px: no page overflow; drawer opens and contains every nav item +
  sign-out; home modules stack matters → activity → security; probed
  primary actions ≥44px.
- **Acceptance:** `test_mobile_home_and_chrome_gate` (C-10).

## T-07 — C-02 documentation trail (no code)

- **Files:** `tests/Feature/AppHomeTest.php` carries
  `test_login_redirects_to_home_logout_to_landing` asserting the brief's
  `/home` target, marked skipped citing 010-D03 / 010-CONFLICT-01, so the
  claim's status is machine-visible, not just prose.
- **Acceptance:** freeze records C-02 as BLOCKED with the decision fork
  from 010-D03.

## T-08 — Gates + freeze

- Pint clean; PHPStan level 7 clean (no baseline exists — zero findings);
  full suite green (238 baseline + new claim tests; C-02's test skipped).
- `99-freeze.md` + `decisions.md` + a progress/dev-journal entry.
- Branch pushed; shared checkout restored to `main`, clean status.
