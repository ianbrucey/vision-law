# T-04 modal browser evidence — 2026-10-07

Real headless-Chromium check (Playwright-core driving the Playwright-bundled
Chromium at `/root/.cache/ms-playwright/chromium-1243`) against
`php artisan serve` on this branch. The modal under test was the real
`<x-ui.modal>` component rendered server-side on a scratch local-only page
(scratch route + view removed after the check — this note and the screenshots
are what remain).

## Results (all green)

| Check | Result |
|---|---|
| Hidden on load (x-cloak, no flash before Alpine boots) | ✅ `isHidden() === true` |
| Alpine 3.17.4 booted | ✅ |
| Opens via `vl-open-modal` window event (`$dispatch('vl-open-modal', {id})`) | ✅ visible, `aria-modal="true"` |
| Focus trap (`x-trap`) — 8× Tab stays inside the dialog | ✅ cycled `confirm-btn` ⇄ `cancel-link`, never left |
| Escape closes | ✅ hidden after `Escape` |
| `vl-close-modal` event closes (Cancel link) | ✅ |
| Scrim click closes | ✅ |

## Screenshots

- `modal-01-closed.png` — page load, modal hidden
- `modal-02-open.png` — modal open (matches mockup §08: Georgia title, muted
  body copy, Cancel link + danger button footer)
- `modal-03-escape-closed.png` — after Escape

## Fixes the check forced

1. **`x-trap` is not in the Alpine core bundle** (3.17): the console warned
   *"You can't use [x-trap] without first installing the 'Focus' plugin"*.
   Added `@alpinejs/focus` (`npm i @alpinejs/focus`) and registered it in
   `resources/js/app.js` via `Alpine.plugin(focus)`. Trap verified working.
2. **Trigger elements must live inside an Alpine scope**: Alpine only
   initializes directives under `x-data`, so a bare `$dispatch` button outside
   any `x-data` never fires. The app shell (`layouts/app.blade.php`, T-05)
   wraps every page in `x-data`, which is the supported configuration; the
   requirement is documented in the `<x-ui.modal>` docblock.
