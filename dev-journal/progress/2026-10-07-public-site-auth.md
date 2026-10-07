# Public site + auth pages (spec 003) — 2026-10-07

> Progress entry for the spec 003 build (tickets T-01…T-07) on
> `feat/003-public-site-auth`. Decisions live in
> `specs/003-public-site-auth/decisions.md` — linked, not duplicated.

## Done

- **T-01** assets + public layout + marketing tokens: two logo files served
  from `public/images/` (byte-identical), `layouts/public.blade.php` shell,
  six dark marketing tokens in `app.css` `:root` + `@theme`,
  `UI_Standards.md` token table + 003-D01 scoped light-only exception.
  (C-05)
- **T-02** landing page: full mockup section map (hero wordmark, nav, trust
  strip, platform grid, auditability, synthetic-badged testimonial, final
  CTA, footer) built with `x-ui.*` components and token utilities only;
  `welcome.blade.php` deleted. (C-01)
- **T-03** sign-in view: centered card, emblem badge, email/password fields,
  single primary "Sign in" button posting to `/login`; "Forgot password?"
  is a `#` placeholder (reset screens are a follow-up spec). (C-02)
- **T-04** registration view: name/email/password (+ hidden
  `invitation_token` from query), 12-char hint, copy adjusted to the 001
  backend contract per the 003-O1 DEFAULT — no org-name field. (C-03)
- **T-05** route + Fortify wiring: `GET /` → landing, `GET /login` and
  `GET /register` behind `guest:web` (register gated by the same
  registration feature flag as POST), redirects per 003-D02 [P]
  (login/logout → `/`, register → `/login`). (C-02, C-03)
- **T-06** feature-test sweep: `tests/Feature/PublicSiteAuthTest.php` —
  every brief verdict named, incl. `FORTIFY_REGISTRATION=false` → `/register`
  404 and the 390px no-horizontal-scroll markup contract. (C-01…C-05)
- **T-07** freeze: reconciled the `welcome` deletion with
  `VisionUiTest.php` (stale `WELCOME` exemption refs removed) and
  `UI_Standards.md` (door-4 law now names `public/`, not welcome; dead
  002-D08 exemption struck); added
  `test_door_4_public_views_exempt_from_h1_rule` to prove the `public/`
  carve-out; evidence under `07-evidence/`; retrospective written.

Final gates: `php artisan test` 124 passed / 1132 assertions,
`pint --test` 112 files clean, `phpstan` (level 7) no errors,
`npm run build` succeeds.

## In progress

- Nothing — spec 003 is frozen. Awaiting coordinator merge of
  `feat/003-public-site-auth` to main.

## Next

- Matter-model spec (Phase 2) — pending Ian's call on whether to kick it
  off; Ian also deferred the matter spec earlier today.
- "Forgot password?" reset screens — follow-up spec, not started.
- App home — supersedes the provisional 003-D02 Fortify redirects when it
  lands.

## Decisions

- 003-D01: dark cinematic hero is a scoped exception to the light-only door
  (marketing landing only; tokens, not `dark:` variants). [D]
- 003-D02: Fortify redirects (login/logout → `/`, register → `/login`)
  until the app home ships. [P]
- 003-O1: org-name field DROPPED; copy adjusted to 001 backend reality.
  [D] — flagged for Ian's final review at freeze.
- Mockup approval: Ian Bruce approved `03-site-mockup.html` + six
  screenshots, 2026-10-07. [D]
- Full log: `specs/003-public-site-auth/decisions.md`.
