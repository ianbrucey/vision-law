# 010 — Archaeology

**Spec:** 010 — App home & authenticated shell (brief APPROVED 2026-10-15)
**Branch:** `feat/010-app-home` from main `ad37eee`
**Date:** 2026-10-15

## Baseline (recorded before any 010 change)

| Gate | Baseline |
|---|---|
| Pint (`vendor/bin/pint --test`) | PASS — 276 files |
| PHPStan level 7 (`phpstan.neon`, no baseline file) | OK — no errors |
| Pest/PHPUnit suite | 238 tests / 3925 assertions green in the GitHub-runner simulator (orchestrator-verified on the main lineage). On the dev-server host directly: **236 passed / 2 failed / 3923 assertions** — the 2 failures are `RegistrationFlagTest`'s flag-off pair, a host artifact: the host's config is cached (config:cache from the domain cutover), so that test's pre-boot env flip of `FORTIFY_REGISTRATION` has no effect. Pre-existing, unrelated to 010, not fixed here. |
| Asset build | public/build present (Vite manifest in tree) |

Baseline debt: none taken into scope. The two CONFLICTs below are spec-level,
not code debt, and are handled in `02-council.md`.

## What exists — REUSE / EXTEND / NEW / CONFLICT

| Area | Finding | Disposition |
|---|---|---|
| App shell frame | `resources/views/layouts/app.blade.php` (spec 002): fixed top bar, Alpine mobile drawer, slots `nav` / `org` / `userMenu` / `header` / `footer`, each rendered only `@isset`. Pages consume it as `<x-layouts.app>`. | REUSE frame; EXTEND with defaults (below) |
| Slot usage | **Zero** views pass `nav`, `userMenu`, or `org` slots (grep over all Blade: no `x-slot name="nav|userMenu|org"`). Every app page renders the bare frame — this is the root cause of the missing chrome, exactly as the brief's seed states. | EXTEND — layout furnishes defaults; page-provided slots still win (`@isset` order preserved) |
| Sign-out control | No Blade view contains a sign-out control. Backend exists: Fortify `POST /logout` (route name `logout`), `redirects.logout = '/'`. 005 suite already asserts `POST route('logout') → /`. | REUSE backend; NEW UI control in the default user menu |
| Public landing | `resources/views/public/landing.blade.php` in `layouts/public`; header is `@include('public.partials.nav')` — dark marketing nav with "Sign in" (`/login`) + "Request a demo" (`#`) actions, zero auth-awareness. 003 suite (`PublicSiteAuthTest`) pins guest rendering. | EXTEND the nav partial only; guest branch stays byte-equivalent in every asserted respect |
| Fortify redirects | `config/fortify.php`: `home = '/home'` (no route exists yet), `redirects = [login '/', logout '/', register '/login', …]`. POST /login is Fortify's `AuthenticatedSessionController`; its `LoginResponse` resolves to `redirect()->intended(Fortify::redirects('login'))`. App bindings: `RegisterResponse` + `FailedTwoFactorLoginResponse` only (AppServiceProvider). No custom `LoginResponse` binding. | See CONFLICT-01 |
| Setup mode (005) | `RestrictToTwoFactorSetup` explicit route-name ALLOWLIST: enrollment surface + spec 008 passkey actions + `logout`. `home` is NOT in it and must not be added (C-08 holds by construction). Flag set/cleared by `EnforceSessionPolicies` + 005 response bindings. | REUSE unchanged |
| Matter access | `AccessControl::effectiveMatterRole()` — org_admin = implicit `matter_owner` on all org matters; every other org role is default-deny without explicit/team grants; cross-org = no access. `Matter::scopeAccessibleBy($user)` encodes the same rule as a query scope (used by MatterService search). | REUSE — home matters + counts + activity all scope through `accessibleBy` |
| Activity read model (006) | `MatterController::feed()` — read model over `audit_events`: `AuditEvent::with('actor')->where('org_id', …)->where('matter_id', …)->orderByDesc('created_at')->orderByDesc('id')`. Human sentences are built by a `$describe` closure local to `matters/_timeline.blade.php`. `AuditEvent` is append-only (DB trigger + model guards); fields: org_id, actor_id, event, auditable_*, matter_id, payload (array cast). | REUSE query shape; home aggregates across accessible matters. Describer stays home-local (no 006 surface change — see Council) |
| Audit writes for fixtures | `AuditLogger::log(event, actor, payload, matter)` — hash-chained per org, advisory-locked. | REUSE in the fixture loader for `audit_event` rows |
| Security status data | `User::hasEnabledTwoFactorAuthentication()` (Fortify trait: secret + confirmed), `User::hasPasskeys()` = `webAuthnCredentials()->whereEnabled()->exists()` (Laragear trait; `webauthn_credentials` table per 008 migration: string PK = credential id, uuid morph to user, `disabled_at` = disabled). Security page route: `two-factor.settings` (GET `user/two-factor`). | REUSE for the C-03 security card |
| Roles/permissions | Spatie HasRoles on User, org-scoped Role rows (PermissionMatrixSeeder seeds per org). Nav admin gating: `hasRole('org_admin')`. Invitations route: `admin.invitations.index`; retention: `admin.retention.policies.index` (both already role-gated server-side). | REUSE |
| Nav targets | `home` (new), `matters.index`, `templates.index`, `admin.invitations.index`, `admin.retention.policies.index`, `two-factor.settings`, `logout` (POST) — all exist except `home`. `search.documents` (GET /search/documents) exists but is a **JSON-only API** (`DocumentSearchController::index(): JsonResponse`) — there is no document-search page. | REUSE + NEW `home`; see CONFLICT-03 |
| Sessions surface | `SessionController` is **JSON-only** ("Backend only — no Blade per 001-D06"): GET /sessions returns JSON. **No sessions page exists anywhere** (no Blade mentions sessions). | See CONFLICT-02 |
| Fixtures infrastructure | `Tests\Helpers\FixtureLoader` loads a spec's `04-fixtures.json` as the single canonical definition: `load()` (001), `loadMatterFixtures()` (006: types organization/user/matter/grant), `loadDocumentFixtures()` (007). 001 loader already contains the 2FA-fixture pattern (`encrypt((new Google2FA)->generateSecretKey())`, 8 recovery codes, `two_factor_confirmed_at`). 006 graph: Sterling + Rival orgs; explicit grants only (roles derive the rest via AccessControl). | EXTEND — `loadHomeFixtures()` on the same pattern, reading `specs/010-app-home/04-fixtures.json`, adding types `audit_event`, `passkey`, `two_factor` |
| Mobile gate (007) | `DocumentMobileTest` technique: fetch page HTML via the test client → standalone-ize (inline built CSS from the Vite manifest, strip external scripts, inject a metrics probe) → headless Chromium (`CHROMIUM_BIN` env, graceful skip when absent) at 390px → assert no page overflow, primary actions ≥44px, tables scroll inside cards; screenshots copied into the spec folder. | REUSE technique in a new `AppHomeMobileTest` (staging in `sys_get_temp_dir()` only) |
| Test style | Class-based PHPUnit (PHPUnit 12) under `tests/Feature`, `RefreshDatabase`, `Tests\TestCase` binds hermetic doubles for 007 services. | REUSE |

## CONFLICTs

### 010-CONFLICT-01 — C-02 vs the 003 and 005 suites (BLOCKER, see Council)

The brief's C-02 requires the Fortify login redirect to change `/` → `/home`
**and** requires "003 auth-page tests unchanged and green". Both cannot hold:

- `tests/Feature/PublicSiteAuthTest.php` (spec 003) —
  `test_login_with_valid_credentials_authenticates` posts valid credentials
  and asserts `assertRedirect('/')`; its docblock cites 003-D02 explicitly.
- `tests/Feature/TwoFactorScreensTest.php` (spec 005) —
  `test_non_admin_without_2fa_logs_in_normally` (005 C-06) posts valid
  credentials and asserts `assertRedirect('/')`.

The login redirect target has exactly one source (`fortify.redirects.login`,
consumed by Fortify's `LoginResponse` via `intended()`). Any implementation
of C-02 — config change or a custom `LoginResponse` binding — flips both
assertions red. The 005 suite is additionally protected by an explicit
standing rule (do not modify; must pass unmodified). **C-02 is therefore
blocked as written**; the named test is delivered with the brief's assertion
and an explicit skip citing this conflict, and the claim's verdict is
recorded NOT GREEN in `99-freeze.md`. The decision needed is recorded in
`02-council.md`.

*Related coupling found in execution:* adding `GET /home` itself (C-01)
silently retargets Laravel's `RedirectIfAuthenticated` default, which
resolves to a route named — or pathed — `dashboard`/`home`; that flipped
the *other* 003 pin (signed-in users on /login and /register land on `/`).
That behavior is the app's contract (003-D02 / O-01), so it is pinned
explicitly in `FortifyServiceProvider` (010-D10) instead of being left to
the framework heuristic.

### 010-CONFLICT-02 — C-05's "Sessions" menu item has no page (deviation, see Council)

The brief's user-menu contents list "Sessions", and O-02 assumed "the
existing sessions page". Archaeology finds no sessions page: the sessions
surface is a JSON API by 001-D06 design. Linking a menu item at a JSON
response would ship a visible defect. The menu ships with Security settings
+ Sign out; the Sessions entry is omitted as a documented deviation. C-05's
verdict (nav + user menu + working sign-out form on the enumerated pages)
does not enumerate menu items and is unaffected.

### 010-CONFLICT-03 — C-05's "Document search" nav item and verdict page have no page (deviation, see Council)

The brief's nav list includes "Document search" and C-05's verdict
enumerates "document search" among the pages that must render the chrome.
Archaeology finds `DocumentSearchController` is JSON-only (the 007 search
backend; same 001-D06 pattern as the sessions API): there is no
document-search page anywhere in the app, and no Blade view references the
route. A nav item pointing at a JSON response would ship a visible defect,
and a non-page cannot render chrome. The default nav omits the entry
(010-D09) and the C-05 verdict is executed against the six real app pages
(home, matters index, matter show, templates, security page, invitations).
Building a document-search page is a new screen — out of 010's scope, same
as the sessions page.

## Files to touch

| File | Action | Reason |
|---|---|---|
| `routes/web.php` | EXTEND — add `GET /home` (name `home`, auth group) | C-01 |
| `app/Http/Controllers/HomeController.php` | NEW | C-01/C-03/C-04/C-09 data assembly |
| `resources/views/home.blade.php` | NEW | Dashboard v1 |
| `resources/views/layouts/app.blade.php` | EXTEND — default chrome when slots absent | C-05/C-06/C-10 |
| `resources/views/components/app/nav.blade.php` | NEW — default primary nav (`<x-app.nav>`) | C-05 |
| `resources/views/components/app/user-menu.blade.php` | NEW — default user menu (`<x-app.user-menu>`) | C-05/C-06 |
| `resources/views/public/partials/nav.blade.php` | EXTEND — auth-aware header actions | C-07 |
| `tests/Helpers/FixtureLoader.php` | EXTEND — `loadHomeFixtures()` + 3 fixture types | All claims (fixtures first) |
| `tests/Feature/AppHomeTest.php` | NEW — C-01…C-09 verdicts (C-02 skipped per CONFLICT-01) | Claims |
| `tests/Feature/AppHomeMobileTest.php` | NEW — C-10 verdict | C-10 |
| `specs/010-app-home/*` | NEW — pipeline artifacts | Protocol |

## Protected files (must not edit)

- `tests/Feature/TwoFactorScreensTest.php` and every spec 005 test — unmodified, must stay green.
- `config/fortify.php` redirect **values** — unchanged per CONFLICT-01 (the 003-D02 comment block stays as-is until the product decision lands).
- `resources/views/matters/_timeline.blade.php` and all 006/007/008 surfaces — no extraction, no behavior change.
- `specs/009-calendaring/**` — untouched (009 is future work; no placeholder widgets).
- The live `vision_law` database — tests run only against phpunit's `vision_law_test` (127.0.0.1:5435).
