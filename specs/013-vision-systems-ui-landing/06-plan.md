# 013 — Vision Systems UI refactor & generic landing page — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Zuri (orchestrator)
**Date:** 2026-11-14
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> **Gate:** this plan is drafted with the spec package for review. It
> takes effect only after `00-brief.md` is APPROVED **and** the mockup
> approval is recorded in `05-ui.md` (Planning Protocol, State 3). No
> ticket executes before both. Slices are separately mergeable: slice 1
> (Tickets 1–5: tokens → components → landing) lands the public face;
> slice 2 (Ticket 6: shell/home) follows behind its own verdicts.

---

## Ticket 1 — Baseline + token layer (all 25 themes + motion tokens)

**Files:** `resources/css/app.css`; `tests/Architecture/VisionSystemsUiTest.php` (create); `docs/UI_Standards.md` door-7 amendment **only if 013-D08 is approved with the brief**
**Inputs:** `03-contract.md` §Design-token contract, §Motion contract; `04-fixtures.json` §themes
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `resources/css/workspace.css`; any `--vl-*` value; all protected files in `01-archaeology.md`

### Work
- [ ] Capture the baseline first: `php artisan test` (count + failures), `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build` — recorded in the ticket evidence. Red baseline → stop and report.
- [ ] Transcribe the 25 theme token sets from the approved decision mockup into the token layer, Ocean Deep as both the rendered default and the `:root` fallback.
- [ ] Add the motion tokens (`--ease`, `--dur-1..3`) and the `prefers-reduced-motion` neutralization block.
- [ ] If 013-D08 was approved: update door 7's test to the amended rule (theme token sets only). If it was **not** approved, stop — the theme build does not proceed around the door.

### Acceptance criteria (binary verdicts from 00-brief.md)
- [ ] C-02 — `test_all_25_theme_token_sets_complete_and_ocean_deep_default` (token-completeness half)
- [ ] C-04 — `test_motion_tokens_and_reduced_motion_rules_present`
- [ ] Architecture suite green (`php artisan test tests/Architecture`)
- [ ] Pint green (`vendor/bin/pint --test`)

---

## Ticket 2 — The approved component inventory as `<x-vs.*>` components

**Files:** `resources/views/components/vs/*.blade.php` (create); `resources/views/patterns.blade.php` (catalogue entries); component render tests
**Inputs:** `03-contract.md` §Component contract; `design/component-library.html` §§1–12
**Dependencies:** Ticket 1
**Forbidden edits:** `resources/views/components/ui/*` (the spec 002 family stands); `.ga` views

### Work
- [ ] Implement the inventory: button, pill, tab, state chip, band, stage rail, protocol box, checkpoint card, composer, evidence row, count tile — token-only styling, states per the contract table.
- [ ] Render each in `/_patterns` in the default theme (local-only route, per 002 C-08).

### Acceptance criteria
- [ ] C-03 — `test_vs_components_render_from_tokens_only` (every component renders with defaults; no raw hex in `components/vs/`)
- [ ] Architecture suite + Pint green

---

## Ticket 3 — Generic Vision Systems landing page

**Files:** `resources/views/public/landing.blade.php`, `resources/views/public/partials/*`, `resources/views/layouts/public.blade.php`; landing content config transcribed once from `04-fixtures.json`; `tests/Feature/Public/LandingVisionSystemsTest.php` (create)
**Inputs:** `03-contract.md` §Landing page section contract + §Copy rules; `04-fixtures.json`; approved `05-ui-mockup.html`
**Dependencies:** Tickets 1–2
**Forbidden edits:** routes (the `/` route stands); auth views and behavior (003/010 boundaries)

### Work
- [ ] Rebuild nav, hero, platform, packs, trust, CTA, footer from `<x-vs.*>` components only, content from the fixtures definition.
- [ ] Mount the theme switcher in the nav (all non-light sets; light sets join only if 013-O02 is decided by build time — otherwise they stay defined-but-unlisted per the stop condition).
- [ ] Retire the `--vl-dark-*` / `--vl-neon*` landing tokens and the old copy (legal-only positioning, testimonial, placeholder labels) with the rebuild (013-D11).
- [ ] Preserve 003 C-04 anonymous-safety and 010 C-07 auth-aware header behavior exactly.

### Acceptance criteria
- [ ] C-01 — `test_landing_is_generic_vision_systems_with_vision_law_first_pack`
- [ ] C-05 — `test_landing_built_only_from_vs_components`
- [ ] C-07 — `test_no_meta_text_on_rendered_surfaces` (landing scope)
- [ ] Spec 003 + 010 public-surface tests green, unmodified
- [ ] Architecture suite + Pint green

---

## Ticket 4 — Landing verification gates (390px + desktop, at load)

**Files:** browser-gate test (007 technique); `07-evidence/` screenshots
**Inputs:** `05-ui.md` §Layout, §Theme behavior
**Dependencies:** Ticket 3
**Forbidden edits:** any production file — a gate failure routes back to its owning ticket, never a test edit

### Work
- [ ] Headless Chromium at 390px and 1440px: sections present, no horizontal overflow, switcher operable, Ocean Deep on first paint.
- [ ] Sample every mockup theme + every light theme visually for contrast (AA body text) and record the pass in evidence.

### Acceptance criteria
- [ ] C-06 — `test_landing_mobile_and_desktop_gates`
- [ ] C-09 (partial) — `npm run build` green; full suite green on the branch

---

## Ticket 5 — Slice 1 freeze (landing)

**Files:** `07-evidence/`, `decisions.md`, `docs/UI_Standards.md` (amendment fold-in), `design/STYLE_GUIDE.md` (promotion, if 013-D09 approved)
**Dependencies:** Tickets 1–4

### Work
- [ ] Verdict output for C-01…C-07, C-09, C-10 saved under `07-evidence/`
- [ ] UI_Standards updated: door 7 amendment (013-D08) + the `<x-vs.*>` family recorded
- [ ] Decisions made during execution recorded in `decisions.md`
- [ ] Slice 1 marked mergeable for Ian's approval — **merge is Ian's act**, per the standing rule

### Acceptance criteria
- [ ] All slice-1 verdicts green with evidence linked
- [ ] CI green on `spec/013-vision-systems-ui`

---

## Ticket 6 — Slice 2: app shell + home onto the Vision Systems system

**Files:** `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php`, shell-level component swaps; token mapping for legacy `<x-ui.*>` surfaces as scoped in the ticket brief at execution time
**Inputs:** `00-brief.md` C-08; spec 010 brief (regression boundary)
**Dependencies:** Ticket 5 (slice 1 frozen)
**Forbidden edits:** `resources/css/workspace.css` and `.ga` views (restyle arrives only through shared token values, with 012 surfaces' own checks green); spec 010 tests (unmodified — they are the verdict)

### Work
- [ ] Restyle the shell + home onto the token layer and `<x-vs.*>` components; chrome content, nav, user menu, and sign-out behavior byte-for-byte the spec 010 behavior.
- [ ] Run the full spec 010 verdict suite + the mobile-gate list including the shell.

### Acceptance criteria
- [ ] C-08 — `test_app_shell_and_home_use_vs_system`
- [ ] Spec 010 suite green unmodified; C-07 sentinel scan extended to shell output
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 7 — Evidence & freeze (whole spec)

**Files:** `07-evidence/`, `08-retrospective.md`, `decisions.md`, `docs/PRODUCT_BLUEPRINT.md` (edit **only if** 013-D10 approved)
**Dependencies:** Ticket 6 (or Ticket 5, if Ian freezes after slice 1)

### Work
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`
- [ ] Verdict output for every claim in `00-brief.md` saved under `07-evidence/`
- [ ] New UI primitives folded into `docs/UI_Standards.md`; style-guide promotion (013-D09) and blueprint edit (013-D10) landed if approved
- [ ] `08-retrospective.md` written: what should change before the next feature
- [ ] Dev-journal progress entry left per the Freeze state

### Acceptance criteria
- [ ] All brief verdicts green with evidence linked
- [ ] CI green on the feature branch; independent review approved; merged per the review protocol — at Ian's word

---

<RULES (from the template, standing):>
- A ticket is complete only when its own verdict AND the standing architecture/style gates are green.
- A failing test is fixed at the cause — assertions are never weakened to get green; if a governing decision changed, update the decision first, then the test.
- Stop on contract drift: if implementation reveals a contract error, update and re-approve the artifact before continuing.
- No opportunistic cleanup: baseline debt is either scoped into the brief or left alone. The spec-012 retro-fit is **not** in this plan (archaeology records it as separate work).
