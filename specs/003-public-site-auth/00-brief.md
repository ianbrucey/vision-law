# 003 — Public site + auth pages — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## Goal

A prospective customer can land on the Vision Law marketing site and create or sign into an account entirely through product-styled pages, with no auth session leaking onto public pages.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Landing page renders at desktop and phone widths with all approved mockup sections | `test_landing_renders_with_all_mockup_sections` — GET `/` → 200; body contains the hero wordmark, nav, trust strip, platform grid, auditability section, testimonial, final CTA, and footer markers; `<meta name="viewport">` present | — (UX necessity: enterprise positioning is the RFI-facing first impression) |
| C-02 | GET `/login` renders the sign-in form; POST `/login` authenticates against the existing Fortify backend | `test_login_page_renders_for_guests` (200 + email/password fields + form posts to `/login`) and `test_login_with_valid_credentials_authenticates` (valid creds → redirect + authenticated) | PLT-01 (authentication) |
| C-03 | GET `/register` renders the registration form; POST `/register` creates the user per the 001 `CreateNewUser` contract (invitation org or oldest open-registration org, least-privileged role) with no auto-login for unverified accounts | `test_register_creates_user_without_auto_login` — POST valid payload → user row created in resolved org with least-privileged role; response redirects to `/login`; `auth()->check()` is false | PLT-01, 001-D09 |
| C-04 | Public pages carry no auth session leakage | `test_public_pages_have_no_user_data` — `/`, `/login`, `/register` render identical markup for signed-in vs anonymous requests except the guest-middleware redirect (authenticated users hitting `/login` or `/register` are redirected away; no name, email, org, or session value appears in any guest view) | — (security hardening; information discipline) |
| C-05 | Logo assets served from `public/images` | `test_logo_assets_served` — GET `/images/vision-emblem.png` and `/images/vision-wordmark.png` → 200 with image content types | — (brand requirement from the approved mockup) |
| C-06 | All quality gates green | CI on the feature branch green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 7), `npm run build` | — (build law) |
| C-07 | Mockup approval recorded | `05-ui.md` carries the mockup approval with approver + date | — (protocol gate; **already true**: Ian approved 2026-10-07) |

## Blueprint anchors

- [D] 001-D01 — Fortify headless backend: no GET view routes from Fortify; this spec registers its own.
- [D] 001-D09 — Self-registration allowed (default open), flag `FORTIFY_REGISTRATION` retained; this spec's GET `/register` must respect the same flag.
- [D] 002-D02 — `docs/UI_Standards.md` is implementation authority: x-ui.\* doors, token-only styling, accessibility rules.
- [D] 003-D01 — Dark cinematic hero is a deliberate, scoped exception to the light-only door (marketing landing only; app behind auth stays light-only).
- [P] 003-D02 — Fortify redirect targets (`login` → `/`, `register` → `/login`) until the app home ships.
- [O] 003-O1 — **Register form org-name field vs backend reality.** The approved mockup's register screen has an "Organization name" field and copy saying self-registration "creates your organization's workspace" with the user as "its first administrator". The 001 backend (`CreateNewUser`) does NOT create an org: it resolves an org from the invitation token, or joins the oldest org with `registration_mode: open`, and assigns the least-privileged role. → **stop condition:** Ian decides before T-04 whether to (a) drop the org-name field and adjust the copy to match the backend (spec default), or (b) extend the backend to create an org + first admin (new contract work, out of this spec's current scope). The builder does not invent org creation.

## Security classification

- **Actors:** anonymous (guest), pending-verification user, verified user (redirected away from guest pages).
- **Data classes touched:** internal (marketing copy, synthetic audit sample); confidential in transit only (passwords — never rendered, never logged).
- **Allow events (audit):** `auth.login` (successful login), `user.created` (successful registration) — both already emitted by the 001 backend; this spec adds no new events.
- **Denial events (audit):** `auth.login.failed`, `auth.login.locked_out` — already emitted by the 001 backend.
- **Leak sentinels:** no user name, email, org name, session value, or credential may appear in the HTML of `/`, `/login`, `/register` for any actor; validation error text must not reveal whether an email is registered (already enforced by 001, preserved here).

## Evidence and fixtures

- Approved visual contract: `03-site-mockup.html` + the six review screenshots (desktop/phone full-page captures for landing, login, register) — the visual source of truth for every section.
- Brand assets: `assets/vision-emblem.png`, `assets/vision-wordmark.png` (chrome/blue-neon variants used in the approved mockup).
- Auth test data: 001's factories (`User`, `Organization`) — referenced by `04-fixtures.json`; no new fixtures are defined here (static pages, guest surface).

## Pre-mortem

- **Likely failure:** *Taste drift from the mockup.* The mockup is hand-written static CSS with raw hex; the build must go through x-ui.\* components and Tailwind `@theme` tokens (door 1: no raw hex). The dark hero's palette therefore becomes NEW theme tokens (`vl-dark-0`, `vl-neon`, …) — a spec'd, reviewed addition, not inline styles smuggled in.
- **Likely failure:** *Fortify view-route conflicts.* Fortify's POST route names `register.store`, `login.store`, `password.email` are taken; the view names `register` and `login` are FREE (Fortify only registers them when `views` is enabled — verified in `vendor/laravel/fortify/routes/routes.php`, and `config/fortify.php` sets `views => false`). T-05 must use exactly these names and verify no collision via `php artisan route:list`.
- **Likely failure:** *The org-name field ships by default.* See 003-O1 — the mockup's "creates your workspace" copy contradicts the backend; building it as-drawn would silently post a field the backend ignores, misrepresenting what registration does.
- **Assumption:** Redirect defaults. `fortify.redirects` is currently all-null, which defaults to the nonexistent `/home`. T-05 sets explicit redirect targets (003-D02 [P]); the app home gets its own spec later.
- **Unknown:** Whether Ian wants the "Request a demo" CTAs wired to anything (mailto, contact form) — spec default is the mockup's dead-link placeholder; the demo backend is a non-goal.
- **Mitigation:** Every risk above has a named ticket and a binary verdict; the org-name question is an explicit [O] with a stop condition.

## Non-goals

- Password-reset, email-verification-notice, and 2FA-challenge screens (their POST backends exist; their views are follow-up specs).
- The demo-request backend (CTAs stay as mockup placeholders).
- The post-login app home (login redirects to `/` until that spec lands).
- Real marketing pages behind nav/footer links (Pricing, Customers, Security detail) — anchors to in-page sections where they exist; the rest stay `#` placeholders as in the mockup.
- Any change to auth backend logic, audit events, or schema.

## Approval gate

- [x] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [x] Product owner approved — **approver:** Ian Bruce · **date:** 2026-10-07 (mockup approval; recorded in `05-ui.md`)
