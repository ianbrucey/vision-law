# T-05 shell + patterns browser evidence — 2026-10-07

Real headless-Chromium check (Playwright-core driving the Playwright-bundled
Chromium) against `php artisan serve` on this branch, hitting the real
`GET /_patterns` route (local env).

## Results (all green, no page errors)

| Check | Result |
|---|---|
| Desktop 1440px: title, single h1, skip link, toast mount (3 demo toasts + 1 in mount) | ✅ |
| Table renders 15 rows + `nav[aria-label="Pagination"]` | ✅ |
| Patterns modal opens via demo button, Escape closes | ✅ |
| Phone 390px: hamburger visible, desktop nav hidden | ✅ |
| Drawer opens (nav links stacked, scrim dims page), Escape closes | ✅ |

## Screenshots

- `patterns-desktop-1440.png` — full page at 1440px (fixed top bar appears
  mid-capture: expected fullPage-screenshot artifact of `position: fixed`)
- `patterns-phone-390.png` — full page at 390px
- `patterns-phone-390-drawer.png` — drawer open on phone

## Implementation notes

- `<x-layouts.app>` is bridged by `App\View\Components\Layouts\App` (class
  component rendering `view('layouts.app')`): the contract path
  `resources/views/layouts/app.blade.php` is not under `components/`, and
  `Blade::anonymousComponentPath()` with a prefix only resolves the
  `<x-layouts::app>` tag form — the class keeps the codebase's dotted
  `<x-layouts.app>` convention.
- `/_patterns` 404s outside `local` via `abort_unless(app()->environment('local'), 404)`
  inside the route (rather than conditional registration) so the suite can
  assert both states; unreachable in production either way. Note for tests:
  `app()->environment()` reads the container `env` binding set at bootstrap,
  not `config('app.env')` — C-08 sets `app()->instance('env', …)` directly.
