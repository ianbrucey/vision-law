# Vision Law — Style Guide ("Glass / Aurora" direction)

Status: **Candidate app-wide aesthetic** — adopted for the Spec 012 (Brain) surface
on 2026-10-30 by Ian's direction; Ian is considering it for the entire app.
Provenance: extracted from the Spec 012 agent-workflow mockup and the mobile
workspace mockup (see `mockups/`). Ian's reference point: "Replit-like,
futuristic — purple and yellow/amber."

This folder is the source of truth for the look. If a screen disagrees with
this guide, the screen is wrong until the guide is deliberately changed.

---

## 1. Design tokens

```css
:root{
  --bg:#070b16;            /* page base */
  --bg-deep:#04060d;       /* mobile shell base */
  --glass:rgba(255,255,255,.045);   /* card surface */
  --glass-2:rgba(255,255,255,.07);  /* raised surface */
  --line:rgba(255,255,255,.09);     /* hairline borders */
  --txt:#e8ecf5;
  --mut:#8b94ab;           /* secondary text */
  --acc:#7c6cff;           /* PRIMARY accent — violet */
  --acc-2:#4f7cff;         /* accent gradient partner — blue */
  --ok:#34c77b;            /* established / satisfied / verified */
  --warn:#e8a13a;          /* AMBER — attention, gray zone, gaps, checkpoints */
  --bad:#e5534b;           /* refuted / failed / danger */
  --cyan:#3fc1c9;          /* viable band */
}
```

Background: deep navy with a radial aurora wash, fixed:
`radial-gradient(1200px 700px at 70% -10%, #16224a 0%, var(--bg) 55%)`
(mobile: `radial-gradient(600px 400px at 80% -5%, #1a2650, var(--bg) 60%)`).

## 2. Color discipline (this is the part that makes it work)

- **Violet (`--acc`) = the agent and the actionable.** Primary buttons, the
  active protocol pill, the current stage, the user's own messages, links of
  intent. If the user can act on it or the agent owns it, it's violet.
- **Amber (`--warn`) = a human decision or an unresolved risk.** Checkpoints,
  gray zones, open gaps, the center of gravity, partial extraction. Amber
  always means "a person needs to look at this." Never use amber decoratively.
- **Green (`--ok`) = settled truth.** Established facts, satisfied elements,
  passed verification. Green is earned by the record, never by optimism.
- **Red (`--bad`) = refuted / excluded / failed.** Rare. Its rarity is the
  point.
- **Cyan (`--cyan`) = viable-but-not-strong middle state** (characterization
  band between strong and gray).
- Everything else is glass and gray. The restraint is what makes the four
  signal colors read instantly.

## 3. Surfaces & shape

- Cards: `--glass` fill, 1px `--line` border, `backdrop-filter: blur(8–10px)`,
  radius **14–16px** (12px for inner rows, 999px for pills/chips).
- Protocol / agent-owned surfaces get a **dashed violet border**
  (`1px dashed rgba(124,108,255,.45)` on `rgba(124,108,255,.06)`) — the visual
  signature of "the agent is working here."
- Checkpoint surfaces get a **1.5px solid amber border** on
  `rgba(232,161,58,.07)`.
- Elevation is by glow, not shadow: the active element carries
  `box-shadow: 0 0 0 4px rgba(124,108,255,.22)` (stage dot) or
  `0 8px 24px rgba(124,108,255,.4)` (FAB).

## 4. Typography

- System sans stack (`-apple-system, "Segoe UI", Inter, Roboto`). No webfont
  dependency — the look must survive offline and in-app rendering.
- Base 14px, line-height 1.45. Chat 13.5–14px.
- **Micro-labels** (section headers, "who" lines, block keys): 10–11px,
  uppercase, letter-spacing .08–.12em, `--mut`, weight 700–800. This is the
  most recognizable typographic habit of the system — use it for every
  structural label.
- Numbers that matter (characterizations, counts) are larger and `--txt`
  against `--mut` labels.

## 5. State language (shared with the Battlefield Model)

Fact/element states always render as small uppercase pills:

| State | Class tone |
|---|---|
| ESTABLISHED / SATISFIED | green |
| ASSERTED / VIABLE | cyan or neutral glass |
| DISPUTED / OPEN / GRAY ZONE | amber |
| REFUTED / EXCLUDED | red |
| HELD BY USER / CHECKPOINT | amber outline |

Characterization bands: **STRONG** (green) · **VIABLE** (cyan) ·
**GRAY ZONE** (amber) · **FAILING** (red).

## 6. Motion & interaction

- Sheets/drawers: `transform .32s ease` slide + scrim fade.
- The stage rail marks *now* with a filled violet dot + glow and completed
  stages with a green check — position must be legible at a glance, mid-scroll.
- Interactive mockup rule (Ian's standing correction): ship raw single-file
  HTML with assets baked in; never screenshots.

## 7. Layout principles

- **Mobile-first is a gate** (390px is a first-class target), not a pass.
- The agent and the record coexist: chat is never a detached modal with its
  own context. One conversation, one session, many doors.
- The case **position** (stage, active protocol, counts, open gaps) is always
  visible or one tap away on every case screen.
- Least friction: multiple-choice and voice answers are first-class composer
  citizens alongside typing; upload lives in the composer.

## 8. What not to do

- No light theme for the Brain surface (the signal colors are tuned for dark).
- No new accent colors. If a state needs a color, it maps to §2 or it stays gray.
- No decorative gradients on content cards; gradients belong to the
  background, the user's messages, avatars, and primary CTAs.
- Don't soften amber into "warning decoration" — if it's amber, a human owes
  a decision.
