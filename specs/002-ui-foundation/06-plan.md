# 002 — UI foundation — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic tickets, components-out (tokens → primitives → composites → shell →
> doors). A ticket is complete only when its own verdicts AND the standing gates
> (architecture suite, Pint, PHPStan) are green. Do not start the next ticket on
> a red gate. Mockup approval (C-11) must land before T-02 — tokens are cheap to
> change, built components are not.
>
> Out of scope (explicit): the 001-D06 auth Blade views — separate ticket this
> foundation unblocks. Livewire of any kind. Dark mode.

---

## Ticket 1 — Tokens, base CSS, Alpine

**Files:** `resources/css/app.css` (extend), `package.json` + lock (add `alpinejs`), `resources/js/app.js` (Alpine boot), `app/Support/formatting.php` (create; composer autoload `files`)
**Inputs:** `01-archaeology.md` baseline; `03-contract.md` token table; 002-D01
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `docs/*`, `specs/002-*/00-brief.md` verdicts, `.github/workflows/tests.yml`

### Work
- [ ] Record baseline: `php artisan test` count, `pint --test`, `phpstan` status.
- [ ] Add the `--vl-*` CSS variables + Tailwind v4 `@theme` color/radius entries per the contract (exact values — copy from the table, do not round or "improve").
- [ ] Base rules: `:focus-visible` 2px `--vl-info` outline; `min-height: 44px` on buttons/inputs (tap targets); Georgia `font-serif` override scoped to titles/headings.
- [ ] `npm install alpinejs`; boot in `app.js` (`import Alpine from 'alpinejs'`); `npm run build` green.
- [ ] Create `app/Support/formatting.php` with `fmtDate()`, `fmtDT()`, `money()` stubs (real implementations land with the first feature that needs them; they must exist so views have one door).

### Acceptance criteria
- [ ] C-01: `test_all_vl_design_tokens_defined` green — every token in the contract exists in compiled CSS
- [ ] `npm run build` succeeds; Pint + PHPStan green

---

## Ticket 2 — Primitive form components

**Files:** `resources/views/components/ui/button.blade.php`, `field.blade.php`, `select.blade.php`, `checkbox.blade.php`, `textarea.blade.php`; `tests/Feature/UiComponentsTest.php` (create)
**Inputs:** `03-contract.md` (component APIs), `05-ui-mockup.html` §§02–03
**Dependencies:** T-01; mockup approval (C-11) must be recorded before starting

### Work
- [ ] Build the five components exactly per the contract's props/slots/fail behaviors (fail-loud on missing label/name; fail-closed on unknown button variant).
- [ ] `field`/`select`/`textarea`/`checkbox`: `id` derived from `name`; `old()` fallback; `$errors` fallback; `(optional)` marker.

### Acceptance criteria
- [ ] C-02 (partial): each of the five renders with defaults, no exception
- [ ] C-03: `test_button_variants_and_sizes_render` green
- [ ] C-04: `test_form_controls_render_label_old_value_and_errors` green
- [ ] C-06 (partial): `test_all_form_controls_have_associated_labels` green for these four
- [ ] Pint + PHPStan green

---

## Ticket 3 — Display components

**Files:** `resources/views/components/ui/{card,page-header,chip,banner,kv,stat}.blade.php`, `app/View/Components/Ui/Status.php` + `status.blade.php`
**Inputs:** `03-contract.md`, `05-ui-mockup.html` §§04–06
**Dependencies:** T-02

### Work
- [ ] Build the six display components per contract. `Status` class holds the canonical label+tone map (unit-testable); unknown values render neutral "Unknown" (fail-closed, never throw).
- [ ] `page-header` renders the page's single `<h1>` (30px Georgia, 26px ≤820px) with crumbs/actions/subnav slots.

### Acceptance criteria
- [ ] C-02 (partial): all six render with defaults
- [ ] C-09: `test_status_component_maps_known_statuses_and_fails_closed` green (every map row + an unknown value)
- [ ] Pint + PHPStan green

---

## Ticket 4 — Composite components

**Files:** `resources/views/components/ui/{table,modal,toast,empty,sub-nav,pagination}.blade.php`
**Inputs:** `03-contract.md`, `05-ui-mockup.html` §§07–08
**Dependencies:** T-03

### Work
- [ ] `table` (head/body slots, scrolls inside card ≤820px), `pagination` (LengthAwarePaginator passthrough).
- [ ] `modal`: Alpine open/close via `vl-open-modal`/`vl-close-modal` events, Escape closes, `x-trap` focus containment. The ONLY dialog door.
- [ ] `toast`: reads `session('toast')` (`tone` + `message`); renders consequence-naming markup.
- [ ] `empty`: dashed card, title + reassuring slot + optional action link.
- [ ] `sub-nav`: section tabs under the page header.

### Acceptance criteria
- [ ] C-02 complete: full inventory renders
- [ ] C-10: `test_toast_renders_from_session_flash` green
- [ ] Modal open/close/Escape behavior verified in a browser check (recorded in `07-evidence/`)
- [ ] Pint + PHPStan green

---

## Ticket 5 — App shell + patterns catalogue

**Files:** `resources/views/layouts/app.blade.php`, `resources/views/patterns.blade.php`, `routes/web.php` (extend)
**Inputs:** `03-contract.md` (layout section), `05-ui-mockup.html` §09
**Dependencies:** T-04

### Work
- [ ] Shell: fixed top bar (wordmark, org slot, user-menu slot), Alpine mobile drawer, `max-width: 1120px` content container, toast mount, skip-link.
- [ ] `GET /_patterns` → `patterns.blade.php` rendering every primitive in every variant/state (synthetic data only); route registered **only** in `local` env, 404 otherwise.

### Acceptance criteria
- [ ] C-07: `test_app_shell_layout_renders` green
- [ ] C-08: `test_patterns_route_renders_in_local_and_404s_in_production` green
- [ ] Pint + PHPStan green

---

## Ticket 6 — Enforced doors (architecture tests)

**Files:** `tests/Architecture/VisionUiTest.php` (create)
**Inputs:** `docs/UI_Standards.md` "Enforced doors" §; `01-archaeology.md` exemptions
**Dependencies:** T-05 (needs the real tree to scan)

### Work
- [ ] Implement all 7 doors: (1) no raw hex in views, (2) buttons only via `x-ui.button`, (3) form controls only via the four wrappers, (4) page titles only via `x-ui.page-header` (auth/welcome exempt), (5) modals only via `x-ui.modal`, (6) `wire:poll` only inside `components/ui/`, (7) light-only (no `dark:`).
- [ ] Each door gets a **negative fixture test**: a violating snippet in a temp fixture fails that door's assertion (proves the door trips, not just passes on a clean tree).

### Acceptance criteria
- [ ] C-05: `VisionUiTest` green on the clean tree AND every negative fixture test fails as expected
- [ ] Pint + PHPStan green

---

## Ticket 7 — Accessibility audit

**Files:** `05-ui.md` (record results), `tests/Feature/UiComponentsTest.php` (extend)
**Inputs:** `05-ui.md` contrast pairs; mockup screenshots
**Dependencies:** T-06

### Work
- [ ] Assert every form control's rendered `label[for]` matches its input `id` (C-06 full).
- [ ] Verify focus-visible styles render on all interactive elements (computed-style check in the headless screenshots or a DOM assertion).
- [ ] Record the contrast audit in `05-ui.md` (pairs already computed: ink 14.5, mut 5.5, brass 4.7, ok 5.0, bad 5.9, info 6.8 — all ≥ 4.5:1).
- [ ] Keyboard walk of modal (trap + Escape) and drawer; skip-link present in shell.

### Acceptance criteria
- [ ] C-06 complete: label association test green for all four controls
- [ ] Contrast table in `05-ui.md` matches computed values (no regressions)
- [ ] Pint + PHPStan green

---

## Ticket 8 — Freeze

**Files:** `docs/UI_Standards.md` (fold-back), `decisions.md`, dev journal entry
**Inputs:** all prior tickets; `07-evidence/`
**Dependencies:** T-07

### Work
- [ ] Fold any new primitives/clarifications back into `docs/UI_Standards.md` (one home per fact).
- [ ] Screenshot comparison: mockup vs `/_patterns` at 1440px and 390px — record deltas, if any, in `07-evidence/`.
- [ ] Write `07-evidence/` (ticket verdicts, screenshots, a11y audit).
- [ ] Dev-journal progress entry; link `decisions.md`.

### Acceptance criteria
- [ ] C-12: `docs/UI_Standards.md` status line reads `[D] Decided` with date
- [ ] Full suite green: `php artisan test`, Pint, PHPStan
- [ ] `07-evidence/` complete
