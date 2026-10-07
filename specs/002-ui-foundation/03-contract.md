# 002 — UI foundation — Component contract

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

> The contract is what views may ask for; nothing else exists. Every component
> lists its props (name · type · default), slots, and the validation behavior
> for bad input. New props go through `docs/UI_Standards.md` first — never
> ad-hoc.

**Conventions:** all components live in `resources/views/components/ui/`.
`{{ $attributes->merge(['class' => ...]) }}` everywhere so callers can extend
classes but never replace the base. IDs: form wrappers generate `id` from
`name` (`field:email` → `id="email"`); explicit `id` prop overrides.

---

## Tokens (the single source)

Defined once in `resources/css/app.css` as CSS variables AND Tailwind v4
`@theme` entries. Values are law — views never hardcode hex.

| Token | Value | Used for |
|---|---|---|
| `--vl-ink` | `#1F2A37` | Primary buttons, headings, dark text |
| `--vl-ink-2` | `#31404F` | Primary button hover, emphasis |
| `--vl-brass` | `#9A6B23` | Attention: decision moments, warnings, "needs review" |
| `--vl-brass-soft` | `#F6EDD9` | Brass tint backgrounds |
| `--vl-paper` | `#F7F8FA` | App background |
| `--vl-card` | `#FFFFFF` | Cards, panels |
| `--vl-line` | `#E3E8EE` | Card borders, dividers |
| `--vl-text` | `#1F2A37` | Body text |
| `--vl-mut` | `#5B6B7C` | Secondary text |
| `--vl-ok` | `#2F7D4F` | Positive / complete |
| `--vl-ok-soft` | `#E6F2E9` | Positive tint |
| `--vl-bad` | `#B23B3B` | Destructive / overdue / denied |
| `--vl-bad-soft` | `#F8E8E8` | Destructive tint |
| `--vl-info` | `#2B5F8A` | Informational |
| `--vl-info-soft` | `#E7EFF6` | Informational tint |

Type: system sans everywhere; **Georgia serif for page titles and card
headings only**. Base 16px; page title 30px (26px ≤820px). Radii: cards 10px,
buttons/inputs 8px, chips 999px. Spacing base 4px; gutters 16px mobile / 32px
desktop; card padding 20–24px; form rows 16px; section gaps 32px.

---

## Components

### `<x-ui.button>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `variant` | `primary\|secondary\|danger\|ghost` | `secondary` | `danger` only for irreversible actions, always paired with a plain-language consequence line |
| `size` | `md\|sm` | `md` | |
| `type` | `button\|submit\|reset` | `button` | Ignored when `href` present |
| `href` | string\|null | `null` | When present, renders `<a>` styled as a button |
| `disabled` | bool | `false` | |
| slot | — | — | The label. **One primary button per view.** Cancellation is a link, never a button. |

Bad input: unknown `variant` → falls back to `secondary` (fail-closed, never throws in render).

### `<x-ui.field>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `name` | string (required) | — | |
| `label` | string (required) | — | Uppercase 12px, rendered above the input |
| `type` | string | `text` | Any valid input type |
| `value` | string\|null | `null` | Falls back to `old(name)` automatically |
| `required` | bool | `false` | |
| `optional` | bool | `false` | Renders "(optional)" marker; do not set with `required` |
| `help` | string\|null | `null` | Hint text under the input |
| `error` | string\|null | `null` | Falls back to `$errors->first(name)`; renders inline under the field |

Renders `<label for="{id}">` + `<input id="{id}" name="{name}">`. Missing `name`/`label` → throws at render (fail-loud: a label-less field is never acceptable).

### `<x-ui.select>`
Same label/`old()`/error contract as `field`, plus:

| Prop | Type | Default | Notes |
|---|---|---|---|
| `options` | `array<string,string>` (required) | — | `value => label` pairs |
| `placeholder` | string\|null | `null` | Disabled first option, e.g. "Choose a matter…" |

Bad input: non-array `options` → throws at render (fail-loud).

### `<x-ui.checkbox>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `name`, `label` | string (required) | — | Label rendered beside the box, clickable |
| `checked` | bool | `false` | Falls back to `old(name)` |
| `value` | string | `"1"` | |
| `help`, `error` | string\|null | `null` | Same contract as `field` |

### `<x-ui.textarea>`
Same contract as `field`, plus `rows` (int, default `4`).

### `<x-ui.page-header>`
| Prop | Type | Default |
|---|---|---|
| `title` | string (required) | — |
| `subtitle` | string\|null | `null` |
| slots | `crumbs`, `actions`, `subnav` | — |

Renders the single `<h1>` of the page (30px Georgia, 26px on phones).

### `<x-ui.card>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string\|null | `null` | Georgia heading |
| `padded` | bool | `true` | |
| slot `header-actions` | — | — | Right-aligned card actions |

### `<x-ui.banner>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `tone` | `info\|success\|warn\|danger` (required) | — | `warn` = privilege/confidentiality notice; `danger` = destructive context |
| `title` | string\|null | `null` | |

Bad input: unknown tone → `info` (fail-closed).

### `<x-ui.chip>`
| Prop | Type | Default |
|---|---|---|
| `tone` | `ok\|warn\|bad\|neutral\|info` | `neutral` |

Metadata labels only — never the sole signal for a status.

### `<x-ui.status>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `type` | `matter\|document\|draft` (required) | — | |
| `value` | string (required) | — | Raw enum value |

Backed by `App\View\Components\Ui\Status` with the canonical label+tone map:

| type | value | label | tone |
|---|---|---|---|
| matter | `open` | Open | info |
| matter | `active` | Active | ok |
| matter | `on_hold` | On hold | warn |
| matter | `closed` | Closed | neutral |
| matter | `archived` | Archived | neutral |
| document | `draft` | Draft | warn |
| document | `final` | Final | ok |
| document | `superseded` | Superseded | neutral |
| draft | `in_progress` | In progress | info |
| draft | `in_review` | In review | warn |
| draft | `approved` | Approved | ok |
| draft | `filed` | Filed | ok |

**Fail-closed:** unknown `value` renders a neutral chip labeled "Unknown" — never raw
enum text, never an exception in render. (The map extends per feature; new values
go through `UI_Standards.md` first.)

### `<x-ui.kv>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `array<array{label: string, value: string}>` (required) | — | Values escaped by default |

### `<x-ui.table>`
Slots: `head`, `body`. Hairline dividers, uppercase 11.5px header, tinted header
row; scrolls inside its card below 820px. Whole-row links: callers wrap row
content in `<a>` — the component never invents navigation.

### `<x-ui.stat>`
| Prop | Type | Default |
|---|---|---|
| `value` | string (required) | Big Georgia value |
| `label` | string (required) | 13px label beneath |

### `<x-ui.modal>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `id` | string (required) | — | Alpine event target |
| `title` | string (required) | — | |
| slots | default (body), `footer` | — | |

The ONLY door for dialogs. Open/close via Alpine: `$dispatch('vl-open-modal', {id: '...'})`
/ `$dispatch('vl-close-modal')`; Escape closes; focus is trapped while open (Alpine
`x-trap`). Never hand-roll a dialog.

### `<x-ui.toast>`
No props in normal use — reads `session('toast')` as `['tone' => 'ok|bad|info', 'message' => '...']`.
**Law:** every mutation flashes a toast that names the *consequence*, not the action
("Draft exported — PDF saved to *Smith v. Jones › Documents*"). Toasts never
repeat confidential content.

### `<x-ui.empty>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string (required) | — | |
| `actionHref`, `actionLabel` | string\|null | `null` | Optional next-action link |

Dashed-border card; slot holds the one reassuring sentence + what will make it
non-empty. Never a blank area.

### `<x-ui.sub-nav>`
| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | `array<array{label: string, href: string, active: bool}>` (required) | — | Renders under the page header; v1 has no sidebars |

### `<x-ui.pagination>`
| Prop | Type | Default |
|---|---|---|
| `paginator` | `LengthAwarePaginator` (required) | Styled wrapper honoring the token set |

## Layout

`resources/views/layouts/app.blade.php` — slots: `header` (optional override),
`content` (required). Fixed top bar (product wordmark, org switcher slot,
user menu slot), mobile nav drawer via Alpine, content `max-width: 1120px`,
toast mount point. Footer link row slot (optional).

## `/_patterns` route

`GET /_patterns` → `patterns.blade.php`, registered **only when
`app()->environment('local')`** (else 404). Renders every component in every
variant/state with synthetic data — the living drift detector.

## Formatting helpers

`fmtDate()`, `fmtDT()`, `money()` — the only source of dates, times, money in
views. Defined in `app/Support/formatting.php` (autoloaded via composer). Never
inline formatting in a view.
