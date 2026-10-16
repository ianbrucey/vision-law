# 010 — Council

**Spec:** 010 — App home & authenticated shell
**Inputs:** `00-brief.md` (APPROVED 2026-10-15), `01-archaeology.md`
**Convention:** [D] decided / [P] proposed / [O] open. Verdicts are binary and
proven by the named tests in `00-brief.md` §4.

## Schema delta

**None.** Spec 010 is a read model over existing tables (`matters`,
`matter_grants`, `audit_events`, `webauthn_credentials`, users) plus Blade
chrome. No migrations, no new columns, no seeders. The only data writes in
the feature suite are fixture rows in the phpunit test database.

## Contract (what execution must satisfy)

### Route

- `GET /home`, route name `app.home` (010-D10), inside the existing authenticated group —
  same middleware posture as `/matters` (`auth`; setup-mode restriction is
  session-flag driven by `RestrictToTwoFactorSetup`, which does NOT allowlist
  `home` — C-08 by construction).
- Guests → 302 `/login` (standard auth middleware). Authenticated,
  non-setup-mode users → 200.

### Home data assembly (C-03, C-04, C-09)

- **My matters:** `Matter::query()->accessibleBy($user)->orderByDesc('updated_at')`,
  count = the same scoped query's count; the module links to `matters.index`.
  No home-specific access logic (006-D: [D] 006 authorization scopes home).
- **Recent activity:** `AuditEvent` rows whose `matter_id` is in the
  accessible-matter set (subquery over the same scope), newest first
  (`created_at` desc, `id` desc — the 006 feed's ordering), with `actor`
  and `matter` eager-loaded, capped at 10. Rendering uses a home-local
  describer (actor name + humanized event + matter title + relative time);
  the 006 timeline blade is not modified.
- **Quick actions:** "New matter" → `matters.create` (same visibility rule
  as the matters index's create affordance); org admins additionally get
  "Invitations" → `admin.invitations.index`.
- **Security status:** two-factor state from
  `hasEnabledTwoFactorAuthentication()` (confirmed state), passkey count
  from `webAuthnCredentials()->whereEnabled()->count()`, link to
  `two-factor.settings`. No secrets, codes, or credential material rendered.
- **Empty state (C-04):** when the user's ORG has no matters at all, the
  matters/activity modules are replaced by one guided empty state
  (`x-ui.empty`): what Vision Law does + the first actions (create a matter;
  org admins: invite your team). No placeholder/deadline/upcoming widgets —
  009's module joins at 009 Plan time.
- **Audit:** home is read-only — no new audit events. Sign-out uses the
  existing Fortify logout (already audited by the 001 listener chain).

### Chrome (C-05, C-06, C-10)

- `layouts/app` renders default chrome **only when the page does not
  provide the slot** (existing `@isset` precedence): default primary nav
  (`<x-app.nav>`: Home, Matters, Templates — Document search omitted per
  010-D09; org admins
  also Invitations, Retention) and default user menu (`<x-app.user-menu>`).
- Desktop: nav in the top bar; user menu = disclosure (user name trigger)
  containing user name + org, Security settings link, and the Sign out
  form (`POST route('logout')`, CSRF). Mobile: the standard nav collapses
  into the existing 002 drawer; the drawer additionally carries the user
  block (name + org), Security settings, and the Sign out form — so every
  nav item and sign-out is reachable at 390px.
- Org slot default: the authenticated user's organization name.

### Landing (C-07, O-01)

- `public/partials/nav` gains an `@auth` branch: "Open app" (→ `home`) and
  a Sign out form replace the Sign in action. The `@guest` branch is the
  current 003 markup unchanged. No other landing change, no redirect of `/`.

## Decisions

| ID | Tag | Decision |
|---|---|---|
| 010-D01 | [D] | **O-01 resolved as proposed** (per orchestrator direction): `/` stays the public landing for everyone; signed-in users get the auth-aware header (C-07), not a redirect. |
| 010-D02 | [D] | **O-02 resolved with a deviation** (CONFLICT-02): no sessions page exists (001-D06 — sessions are a JSON-only API). The user menu ships user name + org, Security settings, Sign out. **The "Sessions" entry is omitted** rather than linking to a JSON response. If a sessions page ships in a later spec, the menu gains the entry then. C-05's verdict is unaffected (it does not enumerate menu items). |
| 010-D03 | [D] | **C-02 BLOCKED** (CONFLICT-01): the login redirect stays `/` in this build. Rationale recorded in archaeology: the change flips a 003 assertion the brief orders unchanged AND an unmodifiable 005 assertion. The named test ships with the brief's `/home` assertion, explicitly skipped citing this decision, so the suite is green and the verdict is honestly NOT GREEN in the freeze. **Decision needed from the product owner** (either suffices): (a) authorize updating the two assertions — `PublicSiteAuthTest::test_login_with_valid_credentials_authenticates` and `TwoFactorScreensTest::test_non_admin_without_2fa_logs_in_normally` — from `/` to `/home` under 010's recorded supersession of 003-D02 (the 005 edit needs an explicit exception to the do-not-modify rule), then flip `redirects.login` to `/home` and un-skip the test; or (b) amend C-02 to retire the redirect change (login keeps landing on `/`, whose auth-aware header + "Open app" is then the designed path into the app). Orchestrator recommendation: (a) — 003-D02's own text anticipated this spec superseding it. |
| 010-D04 | [D] | Chrome defaults live in the layout + two anonymous components (`x-app.nav`, `x-app.user-menu`); page-provided slots always win. No page view is edited to add chrome. |
| 010-D05 | [D] | Home activity uses a home-local describer; no extraction from, or edit to, the 006 timeline blade. |
| 010-D06 | [D] | Fixtures: `specs/010-app-home/04-fixtures.json` is the single canonical definition (protocol one-definition rule); `FixtureLoader::loadHomeFixtures()` is its only loader, extending the 006 runner's types with `audit_event`, `passkey`, `two_factor`. |
| 010-D07 | [D] | No schema changes, no new audit events, no new config. `config/fortify.php` values are untouched in this build (see 010-D03). |
| 010-D08 | [P] | At 009 Plan time, the deadlines module joins `home.blade.php` as an additional module below activity; nothing in 010's markup anticipates it (no placeholder), per the brief's non-goal. |
| 010-D09 | [D] | **"Document search" omitted from the default nav, and from C-05's page enumeration** (CONFLICT-03): `/search/documents` is a JSON-only API with no page (007 backend under the 001-D06 pattern) — the same doctrine as 010-D02. The nav ships Home, Matters, Templates (+ admin entries). When a document-search page ships in a later spec, the entry joins the nav and the enumeration. C-05's substance — chrome by default on every real app page — is built and tested in full on the six real pages. |
| 010-D10 | [D] | **Guest-page redirect pinned explicitly.** Adding `GET /home` has a framework side effect: Laravel's `RedirectIfAuthenticated::defaultRedirectUri()` resolves to a route named — or pathed — `dashboard`/`home`, so the new route silently retargeted the 003-pinned behavior "signed-in users hitting /login or /register land on `/`" (`PublicSiteAuthTest::test_authenticated_users_redirected_off_guest_pages`). Rather than inheriting a heuristic whose meaning changes when routes are added, `FortifyServiceProvider` pins `RedirectIfAuthenticated::redirectUsing(fn () => '/')` — reaffirming 003-D02 / 010-D01 (the landing is the front door; its header is auth-aware). The home route is additionally named `app.home` (not the brief's [P] `home`) as defense in depth. Discovered in execution when the 003 suite went red; the pin restored it unmodified. |

## Claim dispositions going into execution

| Claim | Disposition |
|---|---|
| C-01 | Build to green |
| C-02 | **BLOCKED** — 010-D03; test delivered skipped-with-reason; verdict NOT GREEN |
| C-03 | Build to green |
| C-04 | Build to green |
| C-05 | Build to green (menu composition per 010-D02; nav composition + page enumeration per 010-D09) |
| C-06 | Build to green |
| C-07 | Build to green |
| C-08 | Build to green (regression — behavior exists by construction; test pins it) |
| C-09 | Build to green |
| C-10 | Build to green (007 technique, sys-temp staging) |
