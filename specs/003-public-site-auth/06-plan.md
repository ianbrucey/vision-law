# 003 — Public site + auth pages — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out tickets. Prefer a few independently testable tickets over
> many tiny tickets that cannot stand alone. Parallel workers get disjoint file
> ownership (see 01-archaeology.md).

---

## Ticket 1 — Assets, public layout, marketing tokens

**Files:** `public/images/vision-emblem.png`, `public/images/vision-wordmark.png` (create); `resources/views/layouts/public.blade.php` (create); `resources/css/app.css` (edit — tokens only); `docs/UI_Standards.md` (edit — token table + 003-D01 light-only exception)
**Inputs:** `03-site-mockup.html` CSS variables; approved `assets/*.png`
**Dependencies:** none (baseline ticket)
**Forbidden edits:** auth backend (`app/Actions/Fortify/*`, `FortifyServiceProvider`), migrations, `tests/Architecture/*`

### Work
- [ ] Copy the two logo assets to `public/images/` (byte-identical).
- [ ] Add the six dark marketing tokens to `app.css` `:root` + `@theme` (values in `05-ui.md` §New tokens). No other CSS changes.
- [ ] Create `layouts/public.blade.php`: minimal shell — `<html lang>`, meta viewport, title slot, `@vite`, skip link, toast mount (`x-ui.toast` slot), `{{ $slot }}`. No app topbar, no org/user slots (C-04).
- [ ] Update `docs/UI_Standards.md`: add the tokens to the token table; amend the "Light-only v1" law with the 003-D01 scoped exception (marketing landing only).

### Acceptance criteria
- [ ] `test_logo_assets_served` — GET `/images/vision-emblem.png`, `/images/vision-wordmark.png` → 200, image content types (C-05)
- [ ] Architecture suite green (`php artisan test tests/Architecture`)
- [ ] Pint green (`vendor/bin/pint --test`)

---

## Ticket 2 — Landing page

**Files:** `resources/views/public/landing.blade.php` (+ `public/partials/*.blade.php` as needed) (create); `resources/views/welcome.blade.php` (delete)
**Inputs:** `05-ui.md` §Landing page; approved `03-site-mockup.html`; the six screenshots
**Dependencies:** Ticket 1 (layout + tokens)
**Forbidden edits:** `routes/web.php` (T-05 owns it), auth backend, `docs/UI_Standards.md`

### Work
- [ ] Build the landing view per the section map in `05-ui.md` using x-ui.* components and token utilities only — no raw hex, no inline styles (door 1).
- [ ] Delete `resources/views/welcome.blade.php` (scaffold superseded; its 002-D08 door exemption dies with it — the landing is a fixed marketing layout, exempt from door 3 per the same precedent, recorded in T-07 if VisionUiTest needs an explicit entry).
- [ ] All marketing links: in-page anchors where sections exist (`#platform`, `#audit`, `#customers`); `#` placeholders elsewhere, exactly as mocked. "Request a demo" stays a placeholder (non-goal).
- [ ] Testimonial retains the "SYNTHETIC PLACEHOLDER — NOT A REAL QUOTE" badge.

### Acceptance criteria
- [ ] `test_landing_renders_with_all_mockup_sections` — GET `/` → 200 (via direct view render until T-05 wires the route); body contains hero wordmark, nav, trust strip, platform grid, auditability section, testimonial, final CTA, footer markers; `<meta name="viewport">` present (C-01)
- [ ] `test_landing_has_no_user_data` — no name/email/org/session strings in the HTML (C-04)
- [ ] Architecture suite + Pint green

---

## Ticket 3 — Sign-in view

**Files:** `resources/views/auth/login.blade.php` (create)
**Inputs:** `05-ui.md` §Sign in; approved mockup `#screen-login`
**Dependencies:** Ticket 1
**Forbidden edits:** `routes/web.php`, auth backend

### Work
- [ ] Centered `x-ui.card` with emblem badge, "Welcome back" heading, `x-ui.field` for email (autocomplete username) and password (autocomplete current-password), `x-ui.button` primary "Sign in" — the ONLY primary button on the view.
- [ ] Form posts to `/login` (route name `login.store`, existing Fortify endpoint). Validation errors render inline via `x-ui.field`; `old('email')` preserved.
- [ ] "Forgot password?" link: href="#" per mockup (reset screens are a follow-up spec — noted, not built).
- [ ] "New to Vision Law? Create an account" → `/register` (route name `register`).

### Acceptance criteria
- [ ] `test_login_page_renders_for_guests` — GET `/login` → 200 (via direct view render until T-05); contains email + password fields and a form posting to `/login` (C-02)
- [ ] Architecture suite + Pint green

---

## Ticket 4 — Registration view

**Files:** `resources/views/auth/register.blade.php` (create)
**Inputs:** `05-ui.md` §Create account; 003-O1 answer (see below)
**Dependencies:** Ticket 1
**Forbidden edits:** `routes/web.php`, auth backend (`CreateNewUser` semantics are fixed — the view submits exactly what the backend accepts)

### Work
- [ ] Centered `x-ui.card` with emblem badge, `x-ui.field` for name / email / password (+ hidden `invitation_token` when present in the query string), `x-ui.button` primary "Create account".
- [ ] Form posts to `/register` (route name `register.store`, existing endpoint). 12-char minimum hint under password; terms line per mockup.
- [ ] **003-O1 gate:** build with the DEFAULT (no org-name field, copy adjusted to backend reality) unless Ian answers otherwise before this ticket starts. If Ian approves backend org-creation, this ticket stops — the contract must be amended first (per protocol: contract drift → update and re-approve).

### Acceptance criteria
- [ ] `test_register_page_renders_for_guests` — GET `/register` → 200 (via direct view render until T-05); contains name/email/password fields and a form posting to `/register`; no "organization name" field unless 003-O1 was answered (b) (C-03)
- [ ] Architecture suite + Pint green

---

## Ticket 5 — Route + Fortify wiring

**Files:** `routes/web.php` (edit); `config/fortify.php` (edit); `app/Http/Controllers/Auth/LoginController.php` (create); `app/Http/Controllers/Auth/RegisteredUserController.php` (edit — add `create()` only)
**Inputs:** `03-contract.md` §Routes, §Redirects
**Dependencies:** Tickets 2–4 (views exist to render)
**Forbidden edits:** auth backend logic, `FortifyServiceProvider`, POST routes, migrations

### Work
- [ ] `GET /` → closure returning `view('public.landing')` (replaces the `welcome` closure). Auth: none (public; already on the guest list).
- [ ] `GET /login` → `Auth\LoginController@create`, `guest:web`, name `login`.
- [ ] `GET /register` → `RegisteredUserController@create`, `guest:web`, name `register`, registered only inside the existing `Features::enabled(Features::registration())` gate (same flag as the POST — 001-D09).
- [ ] `LoginController@create` and `RegisteredUserController@create` return their views with NO user data.
- [ ] Set `fortify.redirects`: `login => '/'`, `logout => '/'`, `register => '/login'` (003-D02 [P]); confirm `RedirectIfAuthenticated` sends authenticated users hitting `/login` or `/register` to `/`.
- [ ] Verify `php artisan route:list` shows no Fortify view-route collision (names `login`/`register` free; POST names untouched).

### Acceptance criteria
- [ ] `test_guest_routes_registered_without_collision` — route list contains GET `/` (landing), GET `/login` named `login`, GET `/register` named `register`; Fortify POST routes unchanged (C-02, C-03)
- [ ] `test_login_with_valid_credentials_authenticates` — POST `/login` valid creds → authenticated + redirect to `/` (C-02)
- [ ] `test_register_creates_user_without_auto_login` — POST `/register` valid payload → user row in resolved org with least-privileged role; redirect to `/login` with verification flash; `auth()->check()` false (C-03)
- [ ] `test_authenticated_users_redirected_off_guest_pages` — signed-in GET `/login` or `/register` → redirect, never 200 (C-04)
- [ ] Architecture suite + Pint green

---

## Ticket 6 — Feature tests (verdict sweep)

**Files:** `tests/Feature/PublicSiteAuthTest.php` (create)
**Inputs:** 00-brief.md verdicts; `04-fixtures.json` (points at 001 factories)
**Dependencies:** Ticket 5
**Forbidden edits:** `tests/Architecture/*` (extend only via new file, never weaken)

### Work
- [ ] Consolidate every brief verdict into named tests in one file: `test_landing_renders_with_all_mockup_sections`, `test_login_page_renders_for_guests`, `test_login_with_valid_credentials_authenticates`, `test_register_page_renders_for_guests`, `test_register_creates_user_without_auto_login`, `test_public_pages_have_no_user_data`, `test_logo_assets_served`, `test_guest_routes_registered_without_collision`, `test_authenticated_users_redirected_off_guest_pages`. (T-02–T-05 acceptance criteria above are these same tests — T-06 is the full-file sweep on the wired app, including a registration-flag-off case: `FORTIFY_REGISTRATION=false` → GET `/register` 404.)
- [ ] Responsive check: landing + auth pages at 390px — no horizontal scroll (asserted via markup contract: viewport meta + no fixed-width containers > 100vw; visual check against the phone screenshots).

### Acceptance criteria
- [ ] Full suite green: `php artisan test`
- [ ] `vendor/bin/pint --test` green
- [ ] `vendor/bin/phpstan analyse` green (level 7)
- [ ] `npm run build` succeeds
- [ ] Every claim C-01–C-07 has a green named verdict

---

## Ticket 7 — Evidence & freeze

**Files:** `specs/003-public-site-auth/07-evidence/`, `decisions.md`, `docs/UI_Standards.md` (if T-01's edit needs reconciliation)
**Dependencies:** Tickets 1–6

### Work
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`
- [ ] Verdict output for every claim in 00-brief.md saved under `07-evidence/`
- [ ] New marketing tokens confirmed in `docs/UI_Standards.md` (T-01's edit stands as the Freeze fold-back)
- [ ] `welcome.blade.php` deletion + landing door-3 note reconciled with `tests/Architecture/VisionUiTest.php` (explicit exemption entry if the test requires one)
- [ ] Decisions made during execution recorded in `decisions.md`
- [ ] `dev-journal/progress/` entry left; `08-retrospective.md` written: what should change before the next feature

### Acceptance criteria
- [ ] All brief verdicts green with evidence linked
- [ ] CI green on the feature branch; independent review approved; merged per the review protocol

---

<RULES:>
- A ticket is complete only when its own verdict AND the standing architecture/style gates are green.
- A failing test is fixed at the cause — assertions are never weakened to get green; if a governing decision changed, update the decision first, then the test.
- Stop on contract drift: if implementation reveals a contract error, update and re-approve the artifact before continuing.
- No opportunistic cleanup: baseline debt is either scoped into the brief or left alone.
