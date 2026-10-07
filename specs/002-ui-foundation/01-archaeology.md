# 002 — UI foundation — Archaeology

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

> Scan of what exists before building. Skipping this is how agents duplicate
> things that exist.

---

## REUSE / EXTEND / NEW

| Area | Verdict | Notes |
|---|---|---|
| Tailwind v4 (CSS-first `@theme`, via `@tailwindcss/vite`) | **REUSE** | Already in the scaffold; `resources/css/app.css` imports `tailwindcss`. Tokens go here as `@theme` entries + CSS variables. |
| `resources/css/app.css` | **EXTEND** | Add the `--vl-*` token block; keep the existing `@source` lines and font setup. |
| `resources/views/welcome.blade.php` | REUSE (leave alone) | Scaffold welcome page; not a product screen. Do not restyle it in this feature. |
| `resources/views/components/` | **NEW** | Does not exist. Create `resources/views/components/ui/*.blade.php` — the entire inventory. |
| `resources/views/layouts/` | **NEW** | Does not exist. Create `layouts/app.blade.php` (app shell). |
| `tests/Architecture/` (4 files from spec 001) | REUSE pattern | `AuditWritesTest`, `AuthenticatedByDefaultTest`, `LeakSentinelTest`, `StorageFacadeTest` exist. **NEW** file: `VisionUiTest.php` for the UI doors. |
| Alpine.js | **NEW** | Not in `package.json`/`composer.json`. 002-D01: install via npm (`alpinejs`), bundled by Vite — never CDN. Needed for modal/toast/mobile-drawer interactivity. |
| Livewire | **NEW — deferred** | Not installed. Not needed for this feature (no live surfaces). First live surface triggers its own install decision. |
| Fortify auth views | N/A | Deferred to the 001-D06 ticket; this foundation unblocks it. |
| `routes/web.php` | **EXTEND** | Add the `/_patterns` route (local-env gated). |
| `docs/UI_Standards.md` | **EXTEND** | Proposed [P]→[D] promotion on brief approval (claim C-12); Freeze folds any new primitives back in. |

## Conflicts and constraints

- **Tailwind v4, not v3.** No `tailwind.config.js`; configuration is CSS-first via `@theme` in `app.css`. Builders must not invent a v3 config file.
- **No component classes needed for most primitives** — anonymous Blade components suffice except where logic lives: `x-ui.status` (canonical status map), possibly `x-ui.pagination` (paginator passthrough). Keep classes minimal; put the status map in a dedicated `App\View\Components\Ui\Status` class so it is unit-testable.
- **The `/_patterns` route must never leak into production** — gate on `app()->environment('local')`, abort 404 otherwise. Synthetic data only, always.
- **Auth views are exempt** from the page-header door (per standards) — the architecture test must encode the exemption, not silently pass auth views.

## Files to touch

| File | Why | What changes |
|---|---|---|
| `resources/css/app.css` | Token home | Add `--vl-*` CSS variables + `@theme` entries; base tweaks (focus-visible, tap targets) |
| `resources/views/components/ui/*.blade.php` | The inventory | ~18 new components (see `03-contract.md`) |
| `app/View/Components/Ui/Status.php` | Status map logic | Canonical label+tone map; fail-closed on unknown |
| `resources/views/layouts/app.blade.php` | App shell | Top bar, mobile drawer (Alpine), content container, toast mount |
| `routes/web.php` | Patterns route | `/_patterns`, local-env gated |
| `resources/views/patterns.blade.php` | Catalogue | Renders every primitive (dev drift detection) |
| `tests/Architecture/VisionUiTest.php` | Enforced doors | 7 doors + negative fixtures |
| `tests/Feature/UiComponentsTest.php` | Component verdicts | Render/contract tests for C-02–C-04, C-06–C-10 |
| `package.json` / `package-lock.json` | Alpine | Add `alpinejs`; Vite bundles it |
| `resources/js/app.js` | Alpine boot | `import Alpine from 'alpinejs'; window.Alpine = Alpine; Alpine.start();` |

## Baseline

Ticket T-01 records the test/pint/phpstan baseline before touching anything. If
baseline debt exists, decide then whether cleanup is in scope — do not fix it
mid-feature.
