# 002 — UI foundation — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

> This spec builds the shared visual and component layer every future Vision Law
> screen is composed from. No implementation work begins until this brief is
> APPROVED by the product owner (Ian).

---

## Goal

Every future Vision Law screen is composed from a single approved set of design
tokens and Blade components, so the UI is consistent, accessible, and
enforceable by tests from the first feature onward.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Design tokens defined once as Tailwind v4 `@theme` entries + CSS variables; no raw hex in views | `test_all_vl_design_tokens_defined` — every `--vl-*` token named in `03-contract.md` exists in compiled `app.css`; PASS/FAIL | — (enabling infrastructure for all RFI UI surface) |
| C-02 | Full `<x-ui.*>` component inventory renders with defaults | `test_each_ui_component_renders_with_defaults` — renders every component in the inventory; asserts no exception and expected root element; PASS/FAIL | — (same) |
| C-03 | Button variants `primary/secondary/danger/ghost` and sizes `md/sm` render correctly | `test_button_variants_and_sizes_render` — asserts variant class maps; PASS/FAIL | — (same) |
| C-04 | Form controls (`field/select/checkbox/textarea`) render label, preserve `old()`, show inline errors | `test_form_controls_render_label_old_value_and_errors` — PASS/FAIL | — (same) |
| C-05 | Doors enforced by architecture tests | `tests/Architecture/VisionUiTest.php` green AND each door has a negative fixture test proving it trips on a violating snippet; PASS/FAIL | — (same) |
| C-06 | Accessibility baseline: every form control has an associated label; focus-visible styles present | `test_all_form_controls_have_associated_labels` — rendered output asserts `label[for]` matches input `id`; PASS/FAIL. Contrast pairs recorded in `05-ui.md` | — (same) |
| C-07 | App shell layout (top bar, mobile nav drawer, content max-width 1120px) renders | `test_app_shell_layout_renders` — PASS/FAIL | — (same) |
| C-08 | `/_patterns` catalogue route renders every primitive in local env, 404s elsewhere | `test_patterns_route_renders_in_local_and_404s_in_production` — PASS/FAIL | — (same) |
| C-09 | Statuses never rendered raw: `x-ui.status` maps canonical matter/document/draft statuses; unknown values fail closed to neutral "Unknown" | `test_status_component_maps_known_statuses_and_fails_closed` — PASS/FAIL | — (same) |
| C-10 | Toast renders from session flash and names the consequence | `test_toast_renders_from_session_flash` — PASS/FAIL | — (same) |
| C-11 | Component showcase mockup visually approved by Ian | Approval recorded in `05-ui.md` (DRAFT → MOCKUP APPROVED); binary: recorded or not | — (same) |
| C-12 | `docs/UI_Standards.md` promoted from [P] Proposed to [D] Decided | Status line updated to `[D] Decided — <date>`, recorded in `decisions.md`; binary: done or not | — (same) |

## Blueprint anchors

- [D] D-01 — Greenfield Laravel 13; Laravel owns UI orchestration.
- [D] D-02 — Matter is the single central object and authorization boundary (the `x-ui.status` canonical map starts with matter/document/draft statuses).
- [D] D-10 — Thin-vertical-slice delivery (this foundation unblocks the slice's first screens).
- [P] `docs/UI_Standards.md` is [P] Proposed — **this brief proposes promoting it to [D] on approval** (claim C-12). Until then it is not implementation authority; the mockup approval is.

No [O] item is load-bearing for this feature. Interactivity primitives are decided
in-brief as 002-D01 (Alpine via npm; Livewire deferred).

## Security classification

- **Actors:** all roles (`org_admin`, `attorney`, `paralegal`, `viewer`, `outside_counsel`) plus anonymous (auth/welcome views are exempt from the page-header door per standards).
- **Data classes touched:** internal only — components render no real data. The mockup uses synthetic data exclusively (see `04-fixtures.json`).
- **Allow events (audit):** N/A — UI-only feature, no mutations.
- **Denial events (audit):** N/A.
- **Leak sentinels:** none new in this feature. The sentinel mechanism from spec 001 is extended with an (initially empty) UI-restricted-strings list that future features populate.

## Evidence and fixtures

The mockup (`05-ui-mockup.html`) is the canonical visual reference, built from
synthetic legal data only: matters "Sterling v. Apex Construction"
(MAT-2026-001) and "Confidential internal investigation" (MAT-2026-002),
documents, users — all fictitious. See `04-fixtures.json` for the synthetic
dataset index. No real client data anywhere.

## Pre-mortem

- **Likely failure:** mockup hand-written CSS drifts from Tailwind v4 utility output, so Ian approves a look the build can't reproduce exactly.
  **Mitigation:** exact token values in both; a token→utility mapping table in `05-ui.md`; screenshot comparison of mockup vs `/_patterns` at Freeze (T-08).
- **Assumption:** Ian's visual taste matches the proposed navy/brass/paper direction.
  **Mitigation:** the mockup IS the taste check, and tokens are cheap to change before any component is built. Nothing downstream starts until C-11.
- **Unknown:** future features may need component props not in this contract.
  **Mitigation:** the contract is versioned; new patterns go through `UI_Standards.md` first (existing governance rule) — never ad-hoc props.
- **Mitigation (interactivity):** 002-D01 decides Alpine via npm now (not CDN) so builds are reproducible offline; modal/toast/drawer behavior is specified against Alpine, not hand-rolled JS.

## Non-goals

- Auth Blade views (001-D06) — a separate, bounded ticket. This foundation unblocks it but does not include it.
- Livewire components of any kind (deferred to the first live surface).
- Dark mode, print stylesheets, RTL.
- Real data in `/_patterns` (synthetic only, always).

## Approval gate

- [ ] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated (none load-bearing)
- [ ] Security classification complete
- [ ] **Approval of this brief doubles as the decision to promote `docs/UI_Standards.md` from [P] to [D]** — approver: Ian · date: ________
