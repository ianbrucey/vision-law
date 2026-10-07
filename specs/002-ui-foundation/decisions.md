# 002 — UI foundation — Decision log

> One home per fact. Formal decisions live here; the dev journal links here
> rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-07 | 002-D01 | Alpine.js via npm bundle for v1 interactivity (modal/toast/mobile drawer); Livewire deferred to the first live surface | UI_Standards proposed "sprinkles of JS: Alpine" [P]; neither Alpine nor Livewire is installed; this feature needs modal/toast/drawer behavior but no live surfaces | Alpine via CDN (rejected: not reproducible offline, violates build determinism); Livewire now (rejected: no live surface in this feature to justify the dependency) | `alpinejs` added to package.json; interactivity spec'd against Alpine APIs; a future live surface triggers a new install decision | [D] |
| 2026-10-07 | 002-D02 | Promote `docs/UI_Standards.md` from [P] to [D] — APPROVED by Ian 2026-10-07 with the navy/brass/paper token direction | Standards were written [P] Proposed; this feature implements them | Keep [P] through execution (rejected: builders need implementation authority, and the mockup approval is the substantive review) | UI_Standards.md is now implementation authority for this feature; claim C-12 satisfied | [D] |
| 2026-10-07 | 002-D03 | Mockup built with hand-written CSS using exact token values, not the Tailwind browser CDN | Protocol requires self-contained mockup, no build step | Tailwind v4 browser CDN (rejected: needs network at view time; not archivable offline) | Token→utility mapping table lives in `05-ui.md`; Freeze compares mockup vs `/_patterns` screenshots for drift | [D] |
| 2026-10-07 | 002-D04 | `/_patterns` catalogue route registered only in `local` env (404 elsewhere) | Standards recommend the catalogue "with the first component batch"; a public catalogue in production is clutter and a (minor) information surface | Always-registered (rejected); dev-only route file (rejected: env gate in web.php is simpler and testable) | Claim C-08 asserts both behaviors | [D] |
| 2026-10-07 | 002-D05 | `x-ui.status` fails closed on unknown values (neutral "Unknown" chip) | Statuses must never render raw; throwing in render would break pages on data the UI hasn't learned yet | Throw on unknown (rejected: a new enum value shouldn't white-screen a page); pass through raw text (rejected: violates the never-raw-enum law) | New statuses extend the map via `UI_Standards.md` first | [D] |
| 2026-10-07 | 002-D06 | No Livewire in this feature | No live surfaces in the component inventory; server-render-first law | Install Livewire "just in case" (rejected: dependency without a consumer) | First live surface (draft-job progress, long imports) triggers its own spec + install | [D] |
| 2026-10-07 | 002-D07 | 001-D06 auth Blade views stay a separate ticket; this foundation unblocks but does not include them | 001 deferred auth views pending UI-mockup approval; scope discipline | Fold auth views into T-02/T-04 (rejected: scope creep; the auth ticket has its own verdicts) | The 001-D06 ticket's first dependency is this spec's merge | [D] |
