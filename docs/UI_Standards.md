# Vision Law — UI & UX Standards

**Status:** [D] Decided — October 7, 2026 (approved by Ian with spec 002 brief; navy/brass/paper direction confirmed via mockup)
**Source:** distilled from proven Laravel UI-standards practice, written for Vision Law,
a legal/contract-management application.
were intentionally left behind; what was kept is the *mechanics*: single-source
tokens, one-door component discipline, and architecture tests that enforce the rules.

**The one rule:** decisions are made once, here. Every screen is composed from the
primitives below. A new pattern gets added to this doc *first*, implemented as a
component *second*, used everywhere *third* (per `Planning_Protocol.md`: mockup →
`05-ui.md` → build; Freeze folds new primitives back into this file). If you are
copy-pasting classes between views, stop — extract.

---

## Stack mapping

- Primitives are **Blade components**: `resources/views/components/ui/*.blade.php` →
  `<x-ui.card>`, `<x-ui.chip tone="warn">`, `<x-ui.status type="document" :value="$doc->status">` …
- Tokens live once as CSS variables + Tailwind v4 `@theme` entries in
  `resources/css/app.css` — never hardcode a hex in a view.
- Sprinkles of JS: **Alpine**. Live surfaces (draft-job progress, long-running
  imports): **Livewire**. [P] Proposed — decided per first feature that needs it.
- Formatting helpers are the only source of dates, times, and money
  (e.g. `fmtDate()`, `fmtDT()`, `money()`) — never inline formatting in a view.
- **No third-party form kit in v1** [P]: form controls are plain Blade + Tailwind
  wrappers under `<x-ui.*>`. Keeps the dependency surface small; revisit if a kit
  earns its weight.

## Tokens

Color palette: professional legal — deep navy ink, warm brass for attention,
quiet paper background. Light-only v1.

| Token | Value | Used for |
| --- | --- | --- |
| `--vl-ink` | `#1F2A37` | Primary buttons, headings, dark text |
| `--vl-ink-2` | `#31404F` | Primary button hover |
| `--vl-brass` / `--vl-brass-soft` | `#9A6B23` / `#F6EDD9` | The attention color: decision moments, warnings, "needs review" states |
| `--vl-paper` | `#F7F8FA` | App background |
| `--vl-card` | `#FFFFFF` | Cards, panels |
| `--vl-line` | `#E3E8EE` | Card borders, dividers |
| `--vl-text` / `--vl-mut` | `#1F2A37` / `#5B6B7C` | Body text / secondary text |
| `--vl-ok` / `--vl-ok-soft` | `#2F7D4F` / `#E6F2E9` | Positive, executed, complete |
| `--vl-bad` / `--vl-bad-soft` | `#B23B3B` / `#F8E8E8` | Destructive, overdue, denied |

Typography: **Georgia (serif) for page titles and card headings only** —
everything else is system sans. Base 16px; page title 30px (26px on phones).
Radii: cards 10px, buttons/inputs 8px, chips 999px.

Spacing: base unit 4px; page gutters 16px mobile / 32px desktop; card padding
20–24px; form rows spaced 16px; section gaps 32px.

## Component inventory (canonical)

| Component | Semantics |
| --- | --- |
| **Page header** `<x-ui.page-header>` | `title` + subtitle slot + `actions` slot. Every page starts here; the subtitle explains the page in one line. Optional `crumbs` slot for hierarchy (e.g. Matters › *Smith v. Jones* › Drafts) |
| **Card** `<x-ui.card>` | The container for everything. A card + a heading + one idea |
| **Banner** `<x-ui.banner tone>` | Tones `info / success / warn / danger`. `warn` = privilege or confidentiality notice ("This matter is marked privileged — do not forward"); `danger` = destructive confirmation context |
| **Chip** `<x-ui.chip tone>` | Tones `ok / warn / bad / neutral / info`. Small metadata labels — never the only signal for a status |
| **Status** `<x-ui.status>` | Renders a matter, document, or draft status from a canonical label+tone map. **Statuses are never rendered as raw enum text** — always through this component |
| **Button** `<x-ui.button>` | Variants `primary / secondary / danger / ghost`; `size` `sm`. **One primary button per view.** `danger` only for irreversible actions, always paired with a plain-language consequence line. Cancellation is a link, never a button |
| **Form field** `<x-ui.field>` | Uppercase 12px label above the input; optional fields marked "(optional)"; validation errors inline under the field with `old()` preserved |
| **Select** `<x-ui.select>` · **Checkbox** `<x-ui.checkbox>` · **Textarea** `<x-ui.textarea>` | Same label/error/`old()` contract as `field`. The four are the only doors for user-facing form controls (hidden inputs exempt) |
| **Empty state** `<x-ui.empty>` | Dashed-border card with one reassuring sentence — never a blank area |
| **Toast** `<x-ui.toast>` | Rendered from session flash. Confirms every mutation by naming the consequence ("Draft exported — PDF saved to *Smith v. Jones › Documents*") |
| **KV rows** `<x-ui.kv>` | Label/value grid for reading a record (matter detail, document metadata) |
| **Table** `<x-ui.table>` | Head/body slots; uppercase 11.5px header row, hairline dividers, tinted header; whole-row links where rows navigate; scrolls inside its card on phones |
| **Stat** `<x-ui.stat>` | Big serif value over a 13px label, inside a card — matter dashboards |
| **Modal** `<x-ui.modal>` | The only door for dialogs (confirmations, pickers). New modals go through this wrapper — never a hand-rolled dialog |
| **Sub-nav** `<x-ui.sub-nav>` | Section tabs inside a context (e.g. matter: Overview · Documents · Drafts · Timeline). Renders under the page header — v1 has no sidebars |

## Component discipline (the doors)

These are the only legal ways to build UI. Everything else is a smell:

1. Buttons: only `<x-ui.button>`.
2. Form controls: only `<x-ui.field>`, `<x-ui.select>`, `<x-ui.checkbox>`,
   `<x-ui.textarea>` (hidden inputs exempt).
3. Page titles: only `<x-ui.page-header>` (auth/welcome exempt — they have fixed layouts).
4. Dialogs: only `<x-ui.modal>`.
5. Livewire polling (`wire:poll`): only inside `resources/views/components/ui/`
   components — never hand-rolled in a page view.
6. Statuses: only `<x-ui.status>`; chips for metadata, never decoration.

## Markup rules

1. **No inline styles.** No raw hex in views — tokens only (enforced by test).
2. **Accessibility is not optional:** every control gets its label through the
   wrapper; focus-visible styles on all interactive elements; icon-only buttons
   carry an accessible name; color is never the only signal (chip + text);
   heading hierarchy is real (`h1` once, in the page header).
3. **Escape everything:** Blade `{{ }}` by default; `{!! !!}` only for
   explicitly sanitized HTML, named and justified in the spec.
4. **Server-render first.** No spinners unless an operation exceeds ~1s;
   Livewire loading states are defined per surface, not default.
5. **Formatting helpers only** for dates, times, money, and durations.
6. **Responsive by construction:** mobile-first classes; wide tables scroll
   inside their card; multi-column grids stack below 820px; tap targets ≥ 44px.
7. Views request only what the feature contract (`03-contract.md`) exposes —
   no reaching past the controller/Livewire component.

## Behavior laws

1. **Every list has an empty state** — reassurance + what will make it non-empty
   ("No documents yet. Uploaded files and generated drafts will appear here.").
2. **Every mutation flashes a toast that names the consequence**, not the action.
3. **Irreversible actions** use the `danger` variant plus a plain-language
   consequence line beside them ("Deleting this draft removes all 4 versions
   permanently."), behind a confirmation modal.
4. **Exclusions and decisions show their reasons** — a denied request, a
   rejected draft version, a conflicted document always says why.
5. **Statuses always render via `<x-ui.status>`.**
6. **Screens handling privileged or confidential material carry the `warn`
   banner** stating the handling rule; privilege is also a chip on the record
   itself. Toasts never repeat confidential content — they name the consequence.
7. **One primary button per view.** Navigation is links; cancellation is a link.
8. **Color is never the only signal** — chip + text, icon + label.
9. **AI-generated content is always labeled as a draft** — through the banner
   or a dedicated chip, never implied as final. No AI output is presented as
   legal advice; the drafting disclaimer lives with the output, not in a
   footnote three screens away.

## Layout

Single column, `max-width: 1120px`, generous top offset under a fixed top bar.
Page = page header (with optional crumbs + sub-nav) → content blocks → (optional)
footer link row. Two-column grids only for true side-by-side comparisons
(e.g. version compare) and card pairs ≥ 820px viewport. No sidebars in v1.

## Mobile is required

Every screen, component, and pattern must work at **390px wide** as well as
desktop; this is part of "done", not polish (already required by
`Planning_Protocol.md`'s mockup gate).

- Design mobile-first: base classes target phones, `sm:`/`lg:` add desktop layout.
- No horizontal page scroll. Wide tables scroll inside their card; grids stack.
- Tap targets ≥ 44px; primary actions go full-width on phones.
- Nav collapses to a drawer; page titles drop to 26px.
- A new view or component is not finished until checked at 390px and desktop
  width (screenshot or browser check).

## Governance

- This doc is versioned with the code. A UI change without a standards change
  is a code smell; a standards change without code is vapor.
- The approved feature mockup (`specs/<NNN>/05-ui-mockup.html`) is the visual
  reference for that feature — when a primitive is ambiguous, build it like
  the approved mockup built it.
- Recommended [P]: a `/_patterns` catalogue route rendering every `x-ui`
  primitive in all tones/variants, so drift is visible at a glance. Add it with
  the first component batch.

## Enforced doors — architecture tests

Laws above are enforced by `tests/Architecture/VisionUiTest.php` (to be created
with the first UI feature; `tests/Architecture/` does not exist yet):

1. **No raw hex in Blade views** — regex `/#[0-9a-fA-F]{6}\b/` fails the suite;
   use the `@theme` tokens.
2. **Buttons only via `<x-ui.button>`** — raw `<button>` markup and any
   third-party button component fail outside `components/ui/`
   (mail templates exempt).
3. **Form controls only via the four wrappers** — raw `<input>` (except
   `type="hidden"`), `<select>`, `<textarea>`, or kit controls fail outside
   `components/ui/`.
4. **Page titles only via `<x-ui.page-header>`** — raw `<h1>` fails outside
   `components/ui/`, auth, and welcome views.
5. **Modals only via `<x-ui.modal>`** — hand-rolled dialogs fail.
6. **`wire:poll` only inside `components/ui/`** — create a component before
   polling anywhere.
7. **Light-only v1** — no `dark:` variants, no `class="dark"` in any view.
8. **Leak sentinels** — restricted strings named in a feature's
   `00-brief.md` (client PII, privilege markers, secrets) must never appear
   in rendered views; scans live in the same test file.
