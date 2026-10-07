# UI foundation (spec 002) — 2026-10-07

> Progress entry for the spec 002 build (tickets T-01…T-08) on
> `feat/002-ui-foundation`. Decisions live in
> `specs/002-ui-foundation/decisions.md` — linked, not duplicated.

## Done

- **T-01** tokens + base CSS + Alpine boot: `--vl-*` CSS vars and Tailwind v4
  `@theme` entries, `:focus-visible` 2px `--vl-info` outline, ≥44px tap
  targets, Georgia titles, `fmtDate()`/`fmtDT()`/`money()` stubs. (C-01)
- **T-02** primitive form components: `button`, `field`, `select`, `checkbox`,
  `textarea` — label/`old()`/error contract, fail-loud on missing
  name/label, fail-closed on unknown button variant. (C-02 part, C-03, C-04)
- **T-03** display components: `card`, `page-header`, `chip`, `banner`, `kv`,
  `stat`, `status` (canonical map in `App\View\Components\Ui\Status`,
  fail-closed "Unknown"). (C-09)
- **T-04** composites: `table`, `modal` (Alpine open/close, Escape, `x-trap`),
  `toast` (session flash, consequence-naming), `empty`, `sub-nav`,
  `pagination`. Browser-verified modal behavior — see
  `specs/002-ui-foundation/07-evidence/modal-t04.md`. (C-02 complete, C-10)
- **T-05** app shell + `/_patterns` catalogue: top bar, Alpine mobile drawer,
  1120px container, toast mount, skip link; route local-only (404 in
  production). Browser-verified — see `07-evidence/patterns-t05.md`.
  (C-07, C-08)
- **T-06** enforced doors: `tests/Architecture/VisionUiTest.php` — 7 doors,
  each with a clean-tree assertion AND a negative fixture that proves the
  door trips (fixtures live in temp dirs, deleted per test). (C-05)
- **T-07** accessibility audit: C-06 complete (label association incl. custom
  ids and error states), focus-visible asserted in source CSS and compiled
  bundle, contrast audit (all 7 pairs ≥ 4.5:1, no regressions), keyboard
  walk (modal trap/Escape, drawer, skip link). Recorded in `05-ui.md`.
- **T-08** freeze: fold-back into `docs/UI_Standards.md` (door exemptions —
  status line untouched, still `[D] Decided`), mockup-vs-`/_patterns`
  screenshot comparison at 1440px/390px (deltas small and documented, none
  requiring fixes), evidence in `07-evidence/freeze-t08.md`.

Final gates: full `phpunit` green, `pint --test` clean, `phpstan` no errors,
`npm run build` succeeds.

## In progress

- Nothing — spec 002 is frozen. Awaiting coordinator merge of
  `feat/002-ui-foundation` to main.

## Next

- 001-D06 auth Blade views ticket (this foundation unblocks it).
- Phase 2 matter-model spec (per brief) — pending Ian's call on whether to
  kick it off.
- First Livewire surface (draft-job progress / long imports) triggers its
  own install decision (002-D06); `wire:poll` door is armed for it.

## Decisions

- 002-D01…D07: earlier in the spec (Alpine, standards promotion, mockup CSS,
  patterns route gating, status fail-closed, no Livewire, auth views scope).
- 002-D08: door exemptions recorded in `UI_Standards.md` (welcome scaffold
  exempt from doors 1/4/7; shell drawer is the sanctioned door-5 exception).
- 002-D09: leak-sentinel door stays canonical in spec 001's
  `LeakSentinelTest` (C-14) — not duplicated per UI feature.
- Full log: `specs/002-ui-foundation/decisions.md`.
