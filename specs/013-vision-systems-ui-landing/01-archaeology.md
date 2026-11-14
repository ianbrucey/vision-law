# 013 — Vision Systems UI refactor & generic landing page — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** Zuri (orchestrator)
**Date:** 2026-11-14
**Brief:** `00-brief.md` (must be APPROVED before build work starts)

> Verified against the code on `main` (f642bc3) on 2026-11-14 — read, not
> remembered. Where the repo's actual state differs from the assumptions
> this spec was commissioned under, the difference is stated plainly.

---

## Findings

| Thing | Location | Verdict | Constraint / note |
|---|---|---|---|
| Spec 002 design tokens (`--vl-*`, Tailwind v4 `@theme` + CSS vars) | `resources/css/app.css` | EXTEND | The light navy/brass/paper system stays for legacy surfaces until slice 2. The Vision Systems theme token sets are added as their own layer; no `--vl-*` value changes in slice 1. |
| `<x-ui.*>` component inventory (18 components: button, field, card, chip, status, modal, toast, table, …) | `resources/views/components/ui/` | REUSE | Remains the door for app forms/admin surfaces. It cannot express the Glass/Aurora inventory — that is precisely why the door exemptions below exist. |
| Architecture doors + tests | `tests/Architecture/VisionUiTest.php`, `docs/UI_Standards.md` | EXTEND | Doors 1–6 and 8 stand. **Door 7 (light-only v1) CONFLICTS with the decided 25-theme system** — resolution is the standards amendment proposed in `decisions.md` (013-D08, [P]), approved with the brief; the door test changes only per that amendment. |
| Spec 003 public landing (route `/`, layout, partials) | `resources/views/public/landing.blade.php`, `resources/views/public/partials/{nav,hero}.blade.php`, `resources/views/layouts/public.blade.php` | EXTEND | Route, layout, and anonymous-safety behavior (003 C-04) are reused; every section's content and styling are rebuilt from the new components per `03-contract.md`. Door 4 exemption for `public/*` stands. |
| 003-D01 dark-marketing tokens (`--vl-dark-*`, `--vl-neon*`) | `resources/css/app.css` | CONFLICT | Dark-via-dedicated-tokens was the v1 workaround for the light-only door. The theme system supersedes it: the landing's dark treatment becomes theme token sets. Tokens retire when the landing rebuild lands (013-D11). |
| Current landing copy | `resources/views/public/landing.blade.php` + partials | CONFLICT | Legal-only positioning throughout ("Enterprise legal platform", "Built for the way legal teams actually work"). Two copy defects the new contract's rules exist to kill: (1) a rendered testimonial labeled "SYNTHETIC PLACEHOLDER — NOT A REAL QUOTE" (invented customer + meta text on a live surface); (2) a trust-strip claim "SSO & SCIM ready" while the 2026-11-13 platform audit found no SAML/OIDC in the codebase. Both are removed by the rebuild, not edited in place. |
| Spec 010 app shell + home | `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php` | EXTEND | Slice 2 only. Slice 1 must not change shell behavior; spec 010's verdicts are the regression boundary (brief C-08). |
| Glass / Aurora design source (tokens, color discipline, component library §§1–12) | `design/STYLE_GUIDE.md`, `design/component-library.html` | REUSE | The approved component inventory's origin and the token semantics (`--acc` = agent/actionable, `--warn` = human decision, `--ok` = settled truth, `--cyan` = viable, `--bad` = refuted) carry into the token contract unchanged. Promotion to the Vision Systems style guide is proposed for Freeze (013-D09, [P]). |
| Spec 012 workspace styles (`.ga`-scoped `--ga-*` tokens) | `resources/css/workspace.css` — **on `main`** | REUSE | Correction to the commissioning assumption: the `.ga` workspace CSS is not only on the 012 branch — the first 012 slices (workspace + front-desk) are merged in `main`, and this file ships with them. Slice 1 does not touch it (protected). Two token namespaces (`--ga-*` vs the new theme sets) coexist until slice 2; that coexistence is managed, not denied. |
| Spec 012 branch state | branch `spec/012-chat-live` | REUSE (hands off) | The branch carries **19 commits not on `main`** (later chat/composer/voice slices) and serves the live preview from the main checkout. This spec bases on `main`, depends on none of those 19 commits, and never touches the branch or the checkout. |
| Front-desk panel + workspace views (door-exempt `.ga` surfaces) | `resources/views/home/_frontdesk.blade.php`, `resources/views/matters/workspace*`, `resources/views/layouts/workspace.blade.php` | REUSE | Exempt from doors 2/3 per `docs/UI_Standards.md`; door 1 applies (tokens in `workspace.css`). Untouched by this spec. |
| `/_patterns` catalogue | `resources/views/patterns.blade.php` | EXTEND | Gains the Vision Systems components as they are built (the spec 002 C-08 pattern), so drift stays visible at a glance. |
| Vision Systems decision mockup (25 themes, decisions 1–7) | External design artifact (Muse artifact `vision-systems-ui-mockups`); **not in this repo** | NEW (transcribe) | Token values and Ian's decision record are transcribed into `03-contract.md` and `decisions.md` of this spec. The artifact is design source, not implementation authority; this brief's approval is. |
| Product blueprint §1 (product defined as the legal app) | `docs/PRODUCT_BLUEPRINT.md` | CONFLICT | The blueprint still defines the product as Vision Law the legal application. The 2026-11-14 decision makes Vision Law the first pack of the generic Vision Systems platform. A blueprint edit is proposed (013-D10, [P]); the blueprint remains the authority for the legal pack itself, and this spec does not edit it. |
| Spec 012 spec folder | — (absent from `specs/` on `main`) | NEW (not this spec) | Specs on `main` run 001–010; the 012 design record lives outside `specs/`. Retro-fitting 012 into `specs/` is a separate cleanup Ian has been advised of; it is **not** part of this spec and must not be smuggled into it. |

## Architectural doors this feature must use

- Tokens only: no raw hex in views (door 1) — extended to the new component family by this spec's contract.
- Buttons, form controls, page titles, dialogs through their component doors (doors 2–5), with the recorded `.ga` exemptions left exactly as they are.
- Statuses render through the status component and the style guide's state language — never raw enum text, never renamed (§5 of `design/STYLE_GUIDE.md`).
- The landing requests only what `03-contract.md` exposes: content from the single content definition whose canonical text is `04-fixtures.json`.
- Audit: this feature adds no events and writes none; nothing here routes around `AuditLogger` because nothing here mutates data.

## Files to touch

| File | Action (create/edit) | Owner (worker) | Reason |
|---|---|---|---|
| `resources/css/app.css` | edit | orchestrator (shared file) | Add the Vision Systems token layer: 25 theme token sets + motion tokens per `03-contract.md`; `--vl-*` untouched in slice 1 |
| `resources/views/components/vs/*.blade.php` | create | builder | The approved component inventory as reusable Blade components (contract §Component contract) |
| `resources/views/public/landing.blade.php` + `resources/views/public/partials/*` | edit | builder | Rebuild the landing from the new components and the fixtures content |
| `resources/views/layouts/public.blade.php` | edit | builder | Root theme attribute (Ocean Deep default) + theme switcher mount |
| `resources/views/patterns.blade.php` | edit | builder | Catalogue gains the new components |
| `tests/Feature/Public/LandingVisionSystemsTest.php` | create | builder | Verdicts C-01, C-07 (landing scope) |
| `tests/Architecture/VisionSystemsUiTest.php` | create | builder | Verdicts C-02, C-03, C-04, C-05 (token completeness, token-only components, motion tokens, components-only landing) |
| `tests/Browser/LandingThemeGateTest.php` (or the 007-technique equivalent) | create | builder | Verdict C-06 (390px + desktop at load) |
| `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php` | edit | builder — **slice 2 only** | Shell/home refactor onto the token/component system (C-08) |
| `docs/UI_Standards.md` | edit — **Freeze only, per approved amendment 013-D08** | orchestrator | Door 7 amendment + new component family recorded |
| `design/STYLE_GUIDE.md` | edit — **Freeze only, per 013-D09** | orchestrator | Promotion to the Vision Systems style guide |

## Protected files

- `resources/css/workspace.css` and all `.ga` views (`matters/workspace*`, `layouts/workspace.blade.php`, `home/_frontdesk.blade.php`) — live 012 surfaces; slice 1 never touches them, slice 2 restyles only through shared tokens with the 010/012 verdicts green.
- All migrations and everything under `database/` — this feature has no schema change (`02-schema-delta.md`).
- `app/` domain code, policies, and services — no behavior changes in this spec.
- Branch `spec/012-chat-live` and the main checkout's working tree — the live preview is not a build surface.

## Baseline

- **Not run in this drafting pass.** This spec package was drafted
  documents-only, under an explicit no-database-access constraint, in a
  separate worktree; running the suite here would violate that constraint
  (the suite runs against PostgreSQL per the blueprint).
- **Ticket 1 of `06-plan.md` captures the baseline before any code
  changes:** `php artisan test` (count + failures), `vendor/bin/pint
  --test`, `vendor/bin/phpstan analyse`, `npm run build`, in the feature
  worktree against the dedicated test database. If the baseline is red,
  stop and report — do not build on a broken baseline.
- Framework/package versions checked from the actual lockfile:
  `laravel/framework` **v13.35.0** (`composer.lock`, 2026-11-14).
