# 013 — Vision Systems UI refactor & generic landing page — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** Zuri (orchestrator)
**Date:** 2026-11-14

> First spec under the restored per-feature discipline (Ian, 2026-11-14:
> Vision Law / Vision Systems adopts the FunLynk spec chain, enforced, not
> just documented). No implementation work begins until this brief is
> APPROVED by the product owner.

---

## Goal

A visitor meets one generic **Vision Systems** platform — documents in,
grounded answers and drafts out, with Vision Law presented as its first
vertical pack — and every public and app surface wears the same decided
style guide: 25 switchable themes, the approved component set, and
Apple-soft motion throughout.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | The public landing positions the generic Vision Systems platform, with Vision Law named as the first vertical pack, replacing the current legal-only positioning ("Enterprise legal platform") | `test_landing_is_generic_vision_systems_with_vision_law_first_pack` — GET `/` → 200; body carries the Vision Systems platform headline and names Vision Law as first pack; the legal-only hero line is absent; PASS/FAIL | — (product strategy: the platform decision of 2026-11-14; the RFI-facing legal positioning continues inside the Vision Law pack, not on the platform face) |
| C-02 | The Vision Systems style guide exists as design tokens defined once: all 25 theme token sets from the approved decision mockup, **Ocean Deep the default**, user-switchable without a page reload | `test_all_25_theme_token_sets_complete_and_ocean_deep_default` — every theme slug in `03-contract.md` defines every token in the token contract (a missing token fails); the rendered root carries the Ocean Deep theme by default; the switcher offers all 25; PASS/FAIL | — (UX necessity: decided design system, Ian 2026-11-14) |
| C-03 | The approved component inventory is the build set — tokens, buttons, pills, state chips, bands, stage rail, protocol box, checkpoint card, composer, evidence rows, count tiles — implemented as reusable components whose colors come only from tokens | `test_vs_components_render_from_tokens_only` — every component in the contract inventory renders with defaults; the architecture suite proves no raw hex in the new component views (door 1 extended); PASS/FAIL | — (same) |
| C-04 | Apple-soft motion is a system property, not per-screen garnish: shared ease/duration tokens drive entrances and transitions, nothing pops in abruptly, and `prefers-reduced-motion` disables the motion layer | `test_motion_tokens_and_reduced_motion_rules_present` — compiled CSS defines the motion tokens of `03-contract.md`; entrance/transition rules reference them; a `prefers-reduced-motion` block neutralizes them; PASS/FAIL | — (standing UX convention, Ian 2026-11-14) |
| C-05 | The landing page is composed **only** from the approved components (plus the existing sanctioned doors) — no one-off markup families invented for the page | `test_landing_built_only_from_vs_components` — architecture test: public landing views compose components only; raw component-class markup outside the component doors fails; PASS/FAIL | — (component discipline, `docs/UI_Standards.md`) |
| C-06 | Mobile-first is a gate: the landing renders correctly at 390px and at desktop width, verified at load, with the theme switcher operable at both | `test_landing_mobile_and_desktop_gates` — headless Chromium at 390px and 1440px (the spec 007 technique): no horizontal overflow, every contract section present, switcher changes the theme; PASS/FAIL | — (standing mobile law, `docs/UI_Standards.md`) |
| C-07 | No dev/meta/preview text on any rendered surface: no "synthetic", "placeholder", "mockup", TODO, or internal codename copy; sample content reads as plain product illustration | `test_no_meta_text_on_rendered_surfaces` — sentinel scan of rendered landing + refactored shell output against the forbidden-strings list in `04-fixtures.json`; PASS/FAIL | — (standing rule, Ian 2026-11-05: product surfaces carry product copy only) |
| C-08 | Second slice: the authenticated app shell and home are refactored onto the same token/component system, with spec 010 chrome behavior (nav, user menu, sign-out, auth-aware landing header) unchanged | `test_app_shell_and_home_use_vs_system` — shell + `/home` render on the Vision Systems token layer and components; the spec 010 verdict suite stays green unmodified; PASS/FAIL | 010 (regression boundary) |
| C-09 | All quality gates green on the feature branch | CI green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 7), `npm run build`; PASS/FAIL | — (build law) |
| C-10 | The landing mockup is approved before build tickets execute | Approval recorded in `05-ui.md` (DRAFT → MOCKUP APPROVED) with approver + date; binary: recorded or not | — (protocol gate, `docs/Planning_Protocol.md` State 3) |

## Blueprint anchors

- [D] **Vision Systems decisions (Ian, 2026-11-14)** — platform named Vision
  Systems; Vision Law is its first vertical pack; refactor in this repo on a
  new branch; this spec (UI refactor + landing) is the first real spec,
  superseding the tenancy-first recommendation. Recorded in `decisions.md`
  (013-D01…D07).
- [D] D-01 — Greenfield Laravel 13; Laravel owns UI orchestration.
- [D] D-12 — Thin-real integrations; never demo vapor. The landing copy rules
  in `03-contract.md` are this decision applied to marketing: every
  capability line maps to a shipped module or is framed as a pack/roadmap
  item; no invented customers, metrics, pricing, or testimonials.
- [D] `docs/UI_Standards.md` — the doors are implementation authority.
  **Conflict, named:** door 7 (light-only v1) contradicts the decided
  25-theme system. Resolution is a standards amendment proposed in
  `decisions.md` (013-D08, [P]) and approved with this brief; builders do
  not work around door 7 silently, and the door test is updated only per
  the approved amendment.
- [D] `design/STYLE_GUIDE.md` (Glass / Aurora) — color discipline (§2) and
  state language (§5) are the semantic core of the new token contract.
  Promotion to the app-wide Vision Systems style guide is proposed for
  Freeze (013-D09, [P]).
- [D] 003-D01 is **superseded** by this spec: the landing's dark treatment
  stops being a scoped token exception and becomes one theme among 25
  (recorded as 013-D11, marking the 003 decision [S]).
- [D] Spec 010 — the app shell/home behavior is the regression boundary for
  slice 2 (C-08). Spec 010's own non-goals ("no visual redesign of the 002
  shell or the 003 landing", "no theming") bounded spec 010 only; this spec
  is the one that takes that work on.
- [O] 013-O01…O06 (see `decisions.md`) — none is load-bearing for the
  brief's claims; each carries a stop condition there. Builders stop on
  them rather than inventing answers.

## Security classification

- **Actors:** anonymous (landing), authenticated user (theme switching,
  refactored shell/home), all existing org roles unchanged.
- **Data classes touched:** internal only — platform copy and product
  illustrations. **No** confidential, privileged, or work-product data on
  any surface in this feature; the landing stays anonymous-safe per spec
  003 C-04 (identical markup for signed-in vs anonymous requests except
  the auth-aware header of spec 010 C-07).
- **Allow events (audit):** none new — the landing is read-only and theme
  switching is client-side presentation state (see 013-O06).
- **Denial events (audit):** none new.
- **Leak sentinels:** the spec 001 sentinel list stands. New for this
  feature, scanned by C-07 on rendered output: any real client or matter
  name; the strings "SYNTHETIC", "PLACEHOLDER", "mockup", "lorem",
  "Glass / Aurora" (internal design codename — never public copy), and
  any pricing figure or customer name (none exist; see copy rules).

## Production impact & rollback

- **Impact:** none until merge. All work happens on branch
  `spec/013-vision-systems-ui` (based on `main`); the main checkout and
  the live preview it serves are untouched, and `main` does not change
  until Ian approves the merge. At merge, the public face at `/` changes
  positioning (legal-only → generic platform) and the slice-2 shell
  refactor changes the look of every authenticated page — the two slices
  are separately mergeable so the landing can land first.
- **Rollback:** revert the merge commit(s) and redeploy the prior `main`.
  This feature has **no schema change** (see `02-schema-delta.md`), so
  rollback is code-only: no data migration, no down-migration risk. The
  default theme is a token-layer value; reverting it is a one-line change.

## Evidence and fixtures

- `04-fixtures.json` — the landing page's canonical content as data
  (headline, sections, pack list with Vision Law first, forbidden
  strings). One definition only: the build renders from this content;
  tests assert against it.
- `05-ui-mockup.html` — the visual contract for the landing (seven
  representative themes live-switchable; all 25 are in `03-contract.md`).
- Design source: `design/STYLE_GUIDE.md` + `design/component-library.html`
  (the approved inventory's origin) and the Vision Systems decision
  mockup (Ian's approvals of 2026-11-14, transcribed into `decisions.md`).

## Pre-mortem

- **Likely failure:** *Two design systems collide.* The light `--vl-*`
  system (spec 002) and the dark `.ga` Glass/Aurora scope (spec 012)
  already coexist under door exemptions; a careless unification breaks
  the live workspace or front-desk panel. **Mitigation:** slice 1 does
  not touch `resources/css/workspace.css` or the `.ga` views (protected
  files); unification happens only in slice 2, behind the spec 010
  verdicts.
- **Likely failure:** *Door 7 blocks the build.* The theme system
  contradicts light-only v1 as written. **Mitigation:** the amendment is
  proposed in this spec and approved with the brief (013-D08) — no silent
  workarounds, no mid-build standards edits.
- **Likely failure:** *Copy overclaims.* The current landing asserts
  "SSO & SCIM ready" while the 2026-11-13 platform audit found no
  SAML/OIDC in the codebase, and it renders an invented testimonial
  labeled as a synthetic placeholder. **Mitigation:** contract copy rules
  (D-12 applied): capability lines map to shipped modules or are framed
  as roadmap; the testimonial section is removed, not replaced.
- **Assumption:** theme choice is presentation-only state. If the
  persistence question (013-O06) resolves to server-side storage, the
  schema answer changes — see `02-schema-delta.md`. The build proceeds
  client-side until Ian decides.
- **Unknown:** the landing's long-term public home (own domain vs the
  existing site) — 013-O05. **Mitigation:** this spec builds on the
  existing `/` route; a domain move is deployment work, out of scope.
- **Unknown:** whether all 25 theme token sets survive contact with real
  components unchanged. **Mitigation:** C-02 tests token completeness,
  not taste; per-theme visual QA samples the seven mockup themes plus
  every light theme (the contrast risk lives there).

## Non-goals

- Tenancy/membership changes (the one-org-per-user model stands).
- SSO / SAML / OIDC — described in copy only as the platform's identity
  direction, never claimed as shipped.
- Brain/agent behavior: no Ross logic, prompts, tools, or stage gates
  change. Agent surfaces are restyled only through shared components in
  slice 2.
- Schema changes of any kind (see `02-schema-delta.md`).
- New product pages for future packs; pricing pages; native apps.
- Any change on branch `spec/012-chat-live` or to the live preview.

## Approval gate

- [x] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [ ] Product owner approved — **approver:** <name> · **date:** <YYYY-MM-DD>

<No code, no schema changes, no tickets executed until every box is checked.
`06-plan.md` is drafted alongside for review; it takes effect only after
this brief and the `05-ui-mockup.html` mockup are both approved.>
