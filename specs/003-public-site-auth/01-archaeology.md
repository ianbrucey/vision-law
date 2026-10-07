# 003 — Public site + auth pages — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Brief:** `00-brief.md`

> Read the code — do not guess from memory. Framework API verified against the vendored Laravel 13 / Fortify 1.41.0 sources, not memory.

---

## Findings

| Thing (code, migration, test, doc, config) | Location | Verdict | Constraint / note |
|---|---|---|---|
| Fortify headless backend (`views => false`) | `config/fortify.php` | REUSE | No GET view routes from Fortify; we register our own. View route names `login` / `register` are FREE; `register.store`, `login.store`, `password.email` are TAKEN — verified in `vendor/laravel/fortify/routes/routes.php`. |
| POST `/register` override (no auto-login for unverified) | `app/Http/Controllers/Auth/RegisteredUserController.php` | EXTEND | Add a `create()` method returning the register view; keep `store()` untouched. Mirrors the 001 contract's `RegisteredUserController@create` row. |
| POST `/login` (custom pipeline: lockout → verification gate → 2FA → completion) | Fortify + `app/Actions/Fortify/AttemptLogin.php` etc. | REUSE | Do not touch the pipeline. The login view posts to the existing route. |
| `CreateNewUser` (org resolution: invitation org, else oldest `registration_mode: open` org; least-privileged role; duplicate email → generic message) | `app/Actions/Fortify/CreateNewUser.php` | REUSE | **Contract conflict found:** the mockup's "Organization name" field and "creates your workspace / first administrator" copy do NOT match this backend — no org is ever created. See 003-O1 in the brief. |
| x-ui.\* primitives (field, button, card, banner, toast, page-header, …) | `resources/views/components/ui/` | REUSE | Forms go through `x-ui.field` / `x-ui.button` only (doors 2, 3). |
| App shell | `resources/views/layouts/app.blade.php` | REUSE | NOT used for the public site: the app shell's fixed topbar is for authenticated users. The public site gets its own minimal layout. |
| Authenticated-by-default architecture test | `tests/Architecture/AuthenticatedByDefaultTest.php` | REUSE | Its guest list ALREADY contains `GET login` and `GET register` (rows 28–31) — this spec's routes satisfy the pre-existing list; no edit needed. Any `guest`-middleware route not on that list fails the build. |
| VisionUiTest (doors 1–7) | `tests/Architecture/VisionUiTest.php` | REUSE | Door 1 (no raw hex): the dark hero's palette must be added as `@theme` tokens, never inline hex. Door 3 (page-header): auth views are already exempt ("auth + welcome exempt"). Door 7 (light-only, no `dark:` variants): the dark hero uses tokens, not `dark:` variants — compliant. No new exemptions needed for auth; the landing's fixed marketing layout follows the 002-D08 precedent (exempt from door 3). |
| Leak-sentinel test | `tests/Architecture/LeakSentinelTest.php` | REUSE | 001's suite already scans for privileged-key leaks; T-06 adds guest-page HTML assertions per C-04. |
| Marketing design tokens (navy/brass/paper) | `resources/css/app.css` `@theme` | EXTEND | Add the dark cinematic palette as NEW tokens (`vl-dark-0`, `vl-dark-1`, `vl-neon`, `vl-neon-deep`, `vl-dark-text`, `vl-dark-mut` — values from the approved mockup). Also update `docs/UI_Standards.md` token table + light-only law (003-D01). |
| Logo assets | `specs/003-public-site-auth/assets/` | NEW | Copied to `public/images/` (T-01); referenced via `asset()`. |
| Public layout (minimal shell: skip link, toast mount, no app topbar) | — | NEW | `resources/views/layouts/public.blade.php`. |
| Landing / login / register Blade views | — | NEW | `resources/views/public/landing.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`. |
| Login GET controller | — | NEW | `app/Http/Controllers/Auth/LoginController.php` (`create`). Kept thin; posts to the existing Fortify `/login`. |
| `GET /` closure → `view('welcome')` | `routes/web.php` | EXTEND | `/` now serves the landing view. |
| `resources/views/welcome.blade.php` (Laravel scaffold) | `resources/views/` | CONFLICT | Scaffold placeholder; DELETE — superseded by the landing view at `/`. (Its 002-D08 door exemption dies with it; the landing gets its own door-3 note.) |
| `fortify.redirects` (all null → defaults to nonexistent `/home`) | `config/fortify.php` | CONFLICT | Would redirect successful login/registration to a 404. T-05 sets `login => '/'`, `register => '/login'` (003-D02 [P]) until the app home spec lands. |
| Auth backend, audit events, migrations | `app/Actions/Fortify/*`, `app/Providers/FortifyServiceProvider.php`, `database/migrations/*` | PROTECTED | Not touched by this spec. |

## Architectural doors this feature must use

- Auth is Fortify headless; the app adds GET view routes only — POST behavior is 001's, untouched.
- All form controls through `x-ui.field` / `x-ui.button`; no raw hex in views — marketing tokens go in `app.css` `@theme` first.
- Views request only what the contract exposes; guest views receive no user data.
- Authenticated-by-default: the only new public routes are `GET /`, `GET /login`, `GET /register` (already on the test's guest list).

## Files to touch

| File | Action (create/edit) | Owner (worker) | Reason |
|---|---|---|---|
| `public/images/vision-emblem.png`, `public/images/vision-wordmark.png` | create | T-01 | Serve brand assets (C-05) |
| `resources/views/layouts/public.blade.php` | create | T-01 | Minimal marketing/auth shell |
| `resources/css/app.css` | edit | T-01 | NEW dark marketing `@theme` tokens |
| `docs/UI_Standards.md` | edit | T-01 | Record new tokens + 003-D01 light-only exception (system doc edit proposed alongside the decision) |
| `resources/views/public/landing.blade.php` | create | T-02 | Landing page (C-01) |
| `resources/views/auth/login.blade.php` | create | T-03 | Sign-in view (C-02) |
| `resources/views/auth/register.blade.php` | create | T-04 | Registration view (C-03; blocked on 003-O1 answer for the org-name field) |
| `app/Http/Controllers/Auth/LoginController.php` | create | T-05 | GET `/login` view response |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | edit | T-05 | Add `create()`; `store()` untouched |
| `routes/web.php` | edit | T-05 | GET `/` → landing; GET `/login`; GET `/register` (registration-flag-gated like the POST) |
| `config/fortify.php` | edit | T-05 | Set `redirects.login` / `redirects.register` (003-D02) |
| `resources/views/welcome.blade.php` | delete | T-02 | Scaffold superseded by the landing view |
| `tests/Feature/PublicSiteAuthTest.php` | create | T-06 | All C-01–C-05 verdicts |

<RULE: parallel workers must have disjoint file ownership. Shared files (`routes/web.php`, `config/fortify.php`, `resources/css/app.css`, `docs/UI_Standards.md`) belong to the orchestrator unless explicitly delegated.>

## Protected files

- `app/Actions/Fortify/*`, `app/Providers/FortifyServiceProvider.php` — auth backend logic (001's verdicts depend on it).
- `database/migrations/*` — no schema change in this spec.
- `app/Http/Controllers/Auth/RegisteredUserController.php::store` — untouched (only `create` added).
- `tests/Architecture/*` — enforced doors; tests are extended by T-06, never weakened.

## Baseline

- Tests: 113 passed / 1034 assertions (merge 1f18f72, spec 002 — per merge record; **not re-run here**: the dev-server checkout at `/opt/vision-law` has a production-style vendor without dev dependencies — `phpunit`, `pint`, `phpstan` are absent and `php artisan test` is undefined. Baseline verification for this spec runs in CI on the feature branch.)
- Pint: clean at merge 1f18f72.
- PHPStan: level 7 clean at merge 1f18f72.
- Asset build: `npm run build` success at merge 1f18f72.
- Framework/package versions checked: `laravel/framework` 13.x, `laravel/fortify` 1.41.0, `spatie/laravel-permission` 8.3.0 (from 001-D10 / composer.lock).

<If the baseline is red, stop and report — do not build on a broken baseline. CI is the source of truth for this spec's baseline.>
