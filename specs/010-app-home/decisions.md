# 010 — Decisions

Running record for spec 010 (app home & authenticated shell). Tags per the
Planning Protocol: [D] decided / [P] proposed / [O] open. Full reasoning in
`02-council.md`.

| ID | Tag | Decision |
|---|---|---|
| 010-D01 | [D] | O-01 resolved as proposed: `/` stays the public landing for everyone; signed-in users get the auth-aware header (C-07), not a redirect. |
| 010-D02 | [D] | O-02 resolved with a deviation: the user menu ships name + org, Security settings, Sign out — no "Sessions" entry, because no sessions page exists (001-D06: JSON-only API). Entry returns when a sessions page ships. |
| 010-D03 | [D] | C-02 RESOLVED 2026-10-15 — fork (a), decided by Ian Bruce (product owner): spec 010 supersedes 003-D02 for the login redirect. `config/fortify.php` `redirects.login` flipped `/` → `/home` (logout stays `/`; register/password-reset unchanged; the guest-page pin 010-D10 is untouched). Authorized re-pins, assertion-only: `PublicSiteAuthTest::test_login_with_valid_credentials_authenticates` and `TwoFactorScreensTest::test_non_admin_without_2fa_logs_in_normally` now expect `/home` — the latter under an explicit, one-assertion exception to the 005-suite do-not-modify rule, granted by the product owner for this change. The C-02 named test is un-skipped and GREEN, covering both doors (plain login → `/home`; confirmed-TOTP full flow → challenge → `/home`) and logout → `/`. C-02 verdict: GREEN. |
| 010-D04 | [D] | Chrome defaults live in `layouts/app` + `<x-app.nav>` / `<x-app.user-menu>`; page-provided slots always win; no page view was edited to add chrome. |
| 010-D05 | [D] | Home activity uses a home-local describer; the 006 timeline blade is untouched. |
| 010-D06 | [D] | Fixtures: `specs/010-app-home/04-fixtures.json` is the single canonical definition; `FixtureLoader::loadHomeFixtures()` is its only loader (006 runner types + `two_factor` / `passkey` / `audit_event`). |
| 010-D07 | [D] | No schema changes, no new audit events, no config changes in this build. |
| 010-D08 | [P] | 009's deadlines module joins `home.blade.php` below activity at 009 Plan time; no placeholder ships now. |
| 010-D09 | [D] | "Document search" omitted from the default nav and from C-05's page enumeration (010-CONFLICT-03): `/search/documents` is a JSON-only API with no page. Entry returns when a document-search page ships. |
| 010-D10 | [D] | Guest-page redirect pinned: `RedirectIfAuthenticated::redirectUsing(fn () => '/')` in FortifyServiceProvider, because adding `GET /home` otherwise silently retargets Laravel's default guest redirect (route named — or pathed — dashboard/home) and breaks the 003-pinned landing behavior. Home route named `app.home` as defense in depth. |
| 010-D11 | [D] | Fallout of the 010-D03 resolution, recorded 2026-10-15: the single `redirects.login` value also governs Fortify's `TwoFactorLoginResponse`, so the flip moves the two-factor challenge-completion landing to `/home` as well — the same decided behavior ("login lands on /home") through the challenge door. Three assertions in the spec 008 suite (`PasskeyChallengeTest`: recovery-code and TOTP challenge completions, plus the passkey challenge's JSON `redirect` field — `PasskeyChallengeController` builds it from the same `Fortify::redirects('login')`) pinned that landing to `/` and were re-pinned to `/home`, assertion-only; the freeze-stage archaeology had counted only the two direct POST /login pins. The 005 suite's challenge completions assert only a generic redirect, so no further 005 change was needed. |

Inherited from the brief (not re-decided): 003-D02 superseded for the login
redirect only (effected by the 010-D03 resolution), 002-D02 UI standards, 006
authorization scopes all home data, the 005 allowlist is not widened,
007-D04 mobile gate.
