# 010 — Decisions

Running record for spec 010 (app home & authenticated shell). Tags per the
Planning Protocol: [D] decided / [P] proposed / [O] open. Full reasoning in
`02-council.md`.

| ID | Tag | Decision |
|---|---|---|
| 010-D01 | [D] | O-01 resolved as proposed: `/` stays the public landing for everyone; signed-in users get the auth-aware header (C-07), not a redirect. |
| 010-D02 | [D] | O-02 resolved with a deviation: the user menu ships name + org, Security settings, Sign out — no "Sessions" entry, because no sessions page exists (001-D06: JSON-only API). Entry returns when a sessions page ships. |
| 010-D03 | [D] | C-02 BLOCKED (010-CONFLICT-01): the login redirect stays `/` in this build. Changing it breaks a 003 assertion the brief orders unchanged and an unmodifiable 005 assertion. The claim's named test ships skipped-with-reason; verdict NOT GREEN. Resolution needs the product owner: (a) authorize the two assertion updates under 010's supersession of 003-D02 (incl. an explicit exception for the 005 file), or (b) amend C-02 to retire the redirect change. |
| 010-D04 | [D] | Chrome defaults live in `layouts/app` + `<x-app.nav>` / `<x-app.user-menu>`; page-provided slots always win; no page view was edited to add chrome. |
| 010-D05 | [D] | Home activity uses a home-local describer; the 006 timeline blade is untouched. |
| 010-D06 | [D] | Fixtures: `specs/010-app-home/04-fixtures.json` is the single canonical definition; `FixtureLoader::loadHomeFixtures()` is its only loader (006 runner types + `two_factor` / `passkey` / `audit_event`). |
| 010-D07 | [D] | No schema changes, no new audit events, no config changes in this build. |
| 010-D08 | [P] | 009's deadlines module joins `home.blade.php` below activity at 009 Plan time; no placeholder ships now. |
| 010-D09 | [D] | "Document search" omitted from the default nav and from C-05's page enumeration (010-CONFLICT-03): `/search/documents` is a JSON-only API with no page. Entry returns when a document-search page ships. |
| 010-D10 | [D] | Guest-page redirect pinned: `RedirectIfAuthenticated::redirectUsing(fn () => '/')` in FortifyServiceProvider, because adding `GET /home` otherwise silently retargets Laravel's default guest redirect (route named — or pathed — dashboard/home) and breaks the 003-pinned landing behavior. Home route named `app.home` as defense in depth. |

Inherited from the brief (not re-decided): 003-D02 superseded for the login
redirect only (subject to 010-D03's blocker), 002-D02 UI standards, 006
authorization scopes all home data, the 005 allowlist is not widened,
007-D04 mobile gate.
