# Vision Law — Design System ("Glass / Aurora")

This folder preserves the visual direction Ian approved on 2026-10-30 for the
Spec 012 agentic surface, and that he is considering adopting app-wide.

- **`STYLE_GUIDE.md`** — tokens, color discipline, typography, state language,
  layout principles, and the "what not to do" list. Start here.
- **`component-library.html`** — a living, self-contained reference page:
  every component from the mockups rendered live with its class names.
  Open it in a browser; copy components from it.
- **`mockups/`** — the source mockups the system was extracted from:
  - `agent-workflow.html` — desktop case workspace (position rail, battlefield,
    evidence, agent chat with checkpoint, draft).
  - `mobile-workspace.html` — mobile workspace with two switchable layouts
    (A: tabbed workspace · B: chat-first + record sheet).

Rules:
- Mockups ship as raw single-file HTML with assets baked in (never screenshots).
- Mobile-first: 390px is a first-class gate.
- When a mockup introduces a component that earns its place, add it to
  `component-library.html` and, if it changes a rule, to `STYLE_GUIDE.md` —
  in the same commit.
- This folder is design reference only; it is not served by the app.
