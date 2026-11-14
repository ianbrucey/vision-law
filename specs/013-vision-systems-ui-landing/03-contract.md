# 013 — Vision Systems UI refactor & generic landing page — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** Zuri (orchestrator)
**Date:** 2026-11-14
**Schema:** `02-schema-delta.md` (no schema change)

> The contract is settled BEFORE tickets. This feature has no domain
> services, no mutations, and no new API: its contracts are the **design
> tokens**, the **components**, and the **landing page sections**. Views
> may compose only what this contract exposes.

---

## Design-token contract

Tokens are defined **once** in the stylesheet layer (`resources/css/app.css`,
Tailwind v4 `@theme` entries + CSS variables, per the spec 002 pattern) and
consumed by components — never re-declared per view, never hard-coded
(door 1). The token *semantics* are the Glass / Aurora discipline
(`design/STYLE_GUIDE.md` §2): signal colors keep their meanings in every
theme; a theme changes values, never meanings.

### Token groups

| Group | Tokens | Rule |
|---|---|---|
| Surface | `--bg`, `--bg-deep`, `--glass`, `--glass-2`, `--line`, `--wash` | Page base, deep base, card surface, raised surface, hairline borders, background aurora-wash tint |
| Text | `--txt`, `--mut` | Body text, secondary text. Nothing renders in a third text color |
| Signal | `--acc`, `--acc-2`, `--ok`, `--warn`, `--bad`, `--cyan` | `--acc`/`--acc-2` = the agent & the actionable · `--warn` = a human decision or unresolved risk (never decorative) · `--ok` = settled truth · `--bad` = refuted/failed (rare) · `--cyan` = viable middle state |
| Shape & type | `--radius`, `--radius-sm`, `--font` | Card radius 14–16px family, inner radius, system sans stack (no webfont dependency) |
| Motion | `--ease`, `--dur-1`, `--dur-2`, `--dur-3` | The shared Apple-soft ease-out curve and the duration scale (§Motion contract) |
| Reading | `--reading-lh` | Document/body line-height for the surface; calm-family themes set the looser value (§Theme structure) |

### Theme structure

- A **theme** is a complete assignment of every token above, scoped under
  a root attribute: `[data-vs-theme="<slug>"]`.
- **Completeness rule:** a theme that omits any token is invalid — the
  C-02 verdict test iterates all 25 slugs × all tokens and fails on a gap.
  There is no "partial theme inherits the rest" at the contract level;
  inheritance in the stylesheet is an implementation detail that must
  still produce a complete computed set per theme.
- **Default:** `ocean-deep` (013-D05). The server renders the root
  attribute with the default; the same token set is also the `:root`
  fallback so first paint is correct before any script runs.
- **Switching:** client-side only — swapping the root attribute, no
  reload, no request, no server state (013-O06 stop condition). Theme
  changes cross-fade on the shared motion tokens.
- **The 25 sets** (slugs are contract identifiers; names are display
  copy): `aurora`, `earth-grove`, `midnight`, `ocean-deep` (default),
  `ember`, `daylight`, `regal`, `matte-slate`, `matte-sand`,
  `matte-clay`, `matte-olive`, `matte-dusk`, `calm-deep-ocean`,
  `lagoon`, `sage-mist`, `dawn-mist`, `stone-shore`, `twilight-drift`,
  `liberty-dusk`, `barn-stone`, `parchment`, `walnut`, `canyon-mesa`,
  `adobe-dawn`, `americana-rwb`. Token values are transcribed from the
  approved decision mockup at Ticket 1 and verified by C-02 + visual QA.
- **Light sets** (`daylight`, `matte-sand`, `dawn-mist`, `parchment`,
  `adobe-dawn`) are defined and completeness-tested like the rest, but
  held out of the production switcher until 013-O02 is decided.
- **Calm reading rule:** themes in the calm/heritage families
  (`calm-deep-ocean`, `lagoon`, `sage-mist`, `dawn-mist`, `stone-shore`,
  `twilight-drift`, and the `liberty-dusk`…`americana-rwb` sets) set
  `--reading-lh` to the looser value for document/body text.

### Motion contract (Apple-soft, 013-D07)

- One curve: `--ease` (the shared ease-out curve fixed in the approved
  mockup) and three durations: `--dur-1` (micro: hover, chip, tab),
  `--dur-2` (panels, sheets, drawers), `--dur-3` (entrances, theme
  cross-fades).
- Entrances are graceful: sections rise and fade in; panels glide;
  messages settle. **No element appears by instant display toggle** where
  a transition exists for its class of surface.
- Dragging/resizing is exempt: while a pointer drag is active, transitions
  on the dragged geometry are disabled so the divider tracks 1:1.
- `prefers-reduced-motion: reduce` neutralizes the motion layer
  (animations and transitions off); content is fully usable without it.

## Component contract

The build set is the approved inventory (013-D06), implemented as Blade
components under `resources/views/components/vs/` (`<x-vs.*>`) so the new
family stays distinguishable from `<x-ui.*>` until slice 2 unifies the
shell; the doors in `docs/UI_Standards.md` are amended at Freeze to cover
both families. Origin: `design/component-library.html` §§1–12.

| Component | Contract | States |
|---|---|---|
| Token layer | §Design-token contract above | 25 themes |
| `<x-vs.button>` | Variants `primary` (agent/actionable), `secondary`, `danger`, `ghost`; one primary per view | default · hover · focus-visible · disabled |
| Pills & tabs (`<x-vs.pill>`, `<x-vs.tab>`) | Pill tones map to signal tokens only; tabs are navigation, selected = `--acc` | default · selected · focus-visible |
| State chips (`<x-vs.state>`) | The style guide's state language, **never renamed**: ESTABLISHED/SATISFIED → `--ok` · ASSERTED/VIABLE → `--cyan`/neutral · DISPUTED/OPEN → `--warn` · REFUTED → `--bad` · HELD/CHECKPOINT → `--warn` outline. Color is never the only signal — the label text always renders | one per state |
| Bands (`<x-vs.band>`) | STRONG → `--ok` · VIABLE → `--cyan` · GRAY ZONE → `--warn` · FAILING → `--bad` | one per band |
| Stage rail (`<x-vs.stage-rail>`) | Ordered stages; completed = `--ok` check, current = `--acc` dot + glow, upcoming = muted. Position legible at a glance | done · now · upcoming |
| Protocol box (`<x-vs.protocol>`) | Agent-owned surface: dashed `--acc` border signature; carries the active protocol + its tools line | default |
| Checkpoint card (`<x-vs.checkpoint>`) | Human-decision surface: solid `--warn` border family, header in `--warn`, decision actions as buttons (primary = the decision) | awaiting · resolved |
| Composer (`<x-vs.composer>`) | Full-width field + action strip; attach and voice are first-class citizens; send is the one primary action | empty · typing · sending · disabled |
| Evidence rows (`<x-vs.evidence-row>`) | Document icon, title, meta line, **page anchor** chip; tags ride the row | default · flagged (low-confidence → `--warn` tag) |
| Count tiles (`<x-vs.count>`) | Big value over a micro-label; values in `--txt`, labels in `--mut` | default |

**Color rule (all components):** components reference tokens only. A raw
hex, rgba, or hsl value in a `components/vs/` view fails the architecture
suite (door 1 extended, C-03). Elevation is by glow on dark themes and by
the token-defined surface step on flat/light themes — never by a
hard-coded shadow color.

## Landing page section contract

Route: `GET /` — public, anonymous-safe (spec 003 C-04 stands), with the
auth-aware header behavior of spec 010 C-07 preserved. Layout:
`layouts/public`. Content source: the single content definition whose
canonical text is `04-fixtures.json`. Sections, in order:

| # | Section | Built from | Content rule |
|---|---|---|---|
| 1 | Nav | button, pill | Brand = Vision Systems; theme switcher mounted here; Sign in is a link, Request access is the one primary |
| 2 | Hero | button, state chips, protocol box, checkpoint card, count tiles | Platform headline + the flow in one breath (ingest → anchor → ground → draft); a live-feeling workspace vignette composed from the components — product illustration, never a screenshot with fake data claims |
| 3 | Platform | evidence rows, count tiles, pills | The six-step pipeline from the fixtures: ingest & OCR → page-anchored store → corpus → grounded agent → human-approved drafts → audit. Every step names a shipped platform module |
| 4 | Packs | card, pill, state chips | Vision Law first and marked live; Permitting & Code marked in development; the pack pattern explained (corpus + templates + workflow, engine unchanged) |
| 5 | Trust | card, band | Audit (append-only, hash-chained), retention & legal holds, access fences, export/deletion. Claims limited to what specs 001/007 shipped |
| 6 | CTA | button | One primary action. No form in this spec — the CTA follows the existing site convention |
| 7 | Footer | — | Vision Systems brand; Vision Law named as a pack; no customer logos, no metrics |

### Copy rules (generic copy — D-12 applied)

1. The subject is the **platform**; Vision Law appears as the first pack,
   never as the product itself.
2. **No invented customers, testimonials, metrics, or pricing.** If a
   number cannot be traced to the platform's own record, it does not
   appear. (The current page's testimonial and placeholder labels are
   removed by this rebuild.)
3. Every capability line maps to a shipped module, or is explicitly
   framed as a pack in development / roadmap direction. Identity/SSO is
   roadmap language only until its own spec ships it.
4. Plain product copy only: no dev/meta/preview language, no internal
   codenames, no spec numbers on the rendered page (C-07 sentinel list).
5. Voice: calm, plain, concrete. The page sells traceability — "shows
   its work" — not adjectives.

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `/` | existing public landing controller/view, rebuilt | public | none — anonymous-safe; no tenant data read (003 C-04) |
| GET | `/_patterns` (local only) | existing catalogue, extended | local env only | 404 outside local (002 C-08 stands) |

No new routes. Theme switching adds no route and no request.

## Requests & validation

None — the feature accepts no user input beyond client-side theme
selection, which is validated by construction (the switcher can only set
a slug from the contract list; an unknown slug falls back to the default).

## Error catalog

| Situation | HTTP | Body | Audit event |
|---|---|---|---|
| Landing requested by any actor | 200 | the page | none |
| Unknown theme slug in client state | — (client-side) | falls back to `ocean-deep` | none |

## Audit events

None new, allow or deny. This feature mutates no data (see
`02-schema-delta.md`).

## Idempotency & transactions

N/A — no mutations, no POST endpoints.

## Role-visible data

| Role | Sees | Must never see |
|---|---|---|
| anonymous | the landing page, identical for all anonymous visitors | any user, org, matter, or session data (003 C-04) |
| authenticated | the same landing + the auth-aware header (010 C-07) | nothing beyond their own sign-in state in the header |

## External integration failure behavior

No external services are called by this feature. The page must render
fully with no network dependency beyond the app's own compiled assets
(no webfonts, no CDN calls — the style guide's system-stack rule).

## AI involvement

No AI involvement in this feature's build or runtime. The landing
*describes* the platform's agent capability in copy, under the copy rules
above; no agent code, prompt, or tool changes here (brief non-goals).
