# T-08 Freeze evidence — spec 002 UI foundation — 2026-10-07

## Ticket verdicts

| Ticket | Verdict | Result |
|---|---|---|
| T-01 tokens/base CSS/Alpine | C-01 | Green (prior commit) |
| T-02 primitives | C-02(part)/C-03/C-04/C-06(part) | Green (prior commit) |
| T-03 display | C-02(part)/C-09 | Green (prior commit) |
| T-04 composites | C-02 complete/C-10 + modal browser check | Green (prior commit; see `modal-t04.md`) |
| T-05 shell + patterns | C-07/C-08 + shell browser check | Green (prior commit; see `patterns-t05.md`) |
| T-06 enforced doors | **C-05** | Green — `VisionUiTest` 14/14 (7 doors × clean-tree + negative fixture) |
| T-07 accessibility audit | **C-06 complete** | Green — labels, focus-visible, contrast, keyboard walk |
| T-08 freeze | **C-12** | Green — `docs/UI_Standards.md` status line reads `[D] Decided — October 7, 2026` (verified post-fold-back) |

## Screenshot comparison — mockup vs live `/_patterns`

Captured headless (Playwright-bundled Chromium) against `php artisan serve`
on the feature branch, hitting the real `GET /_patterns` route (local env,
HTTP 200) with a server-minted session.

| File | What |
|---|---|
| `patterns-live-desktop-1440.png` | Full page at 1440px |
| `patterns-live-phone-390.png` | Full page at 390px |
| `05-ui-mockup-desktop-full.png` / `05-ui-mockup-phone-full.png` | Approved mockup (review set, in spec dir) |

Recorded deltas (all small, none requiring fixes):

1. **Condensed catalogue vs annotated mockup** — `/_patterns` is a flatter
   living catalogue: same primitives, fewer annotated demo states. No
   token/type-scale section (tokens live in CSS; type scale is demonstrated
   by the Georgia page title), no crumbs demo in the page-header (slot
   exists, exercised by future features), modal shown as an interactive
   trigger rather than a static open render (T-04 evidence verified
   open/Escape/trap against the same component).
2. **Form controls**: mockup uses a 2-column desktop grid; live is single
   column. Same label/`old()`/error contract.
3. **Buttons**: mockup demos the danger+consequence-line pairing; live states
   the rule as text. Usage pattern, not a component gap.
4. **Status badges**: live shows a subset of the canonical map
   (Open/On hold/Final/In review + fail-closed Unknown); the full map is
   unit-tested by C-09 (`test_status_component_maps_known_statuses_and_fails_closed`).
5. **Visual style**: navy/brass/paper direction, Georgia serif titles, radii,
   spacing — match the mockup. No color or type regressions.
6. **Phone 390px**: single column, full-width primary buttons, stacked stats,
   table scrolls inside its card, drawer nav (verified in `patterns-t05.md`).

## Accessibility audit (T-07)

- C-06 complete: `label[for]` = control `id` for all four wrappers —
  default derivation, custom `id` overrides, and error states
  (`aria-describedby` → `{id}-error`).
- Focus-visible: `:focus-visible { outline: 2px solid var(--vl-info);
  outline-offset: 2px; }` asserted in `resources/css/app.css` AND in the
  Vite-compiled bundle.
- Contrast: computed from `@theme` hex vs white — ink 14.5, text 14.5,
  mut 5.5, brass 4.7, ok 5.0, bad 5.9, info 6.8 — all match the recorded
  pairs, all ≥ 4.5:1. No regressions.
- Keyboard walk: modal `x-trap` + Escape + labelled dialog; drawer Escape +
  trap + accessible icon-button names; skip link targets `<main id="vl-main">`
  on the live page.

## Final gates (2026-10-07, on `feat/002-ui-foundation`)

- `vendor/bin/phpunit` — full suite green (see commit message for counts)
- `vendor/bin/pint --test` — PASS
- `vendor/bin/phpstan analyse --no-progress` — no errors
- `npm run build` — succeeds

## Cleanup notes

- Screenshot scaffolding (dev-server session cookie, `shots@visionlaw.test`
  user + `screenshot-org` org) was removed after capture; no test or app
  code was touched for the screenshots.
- Negative door fixtures for T-06 lived only in temp dirs (deleted per
  test); the real view tree was never polluted.
