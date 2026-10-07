# 003 — Public site + auth pages — Decision log

> One home per fact. Formal decisions live here; the dev journal links here
> rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-07 | 003-D01 | The landing hero's **dark cinematic treatment is a deliberate, scoped exception** to the UI light-only door — it applies to the marketing landing (nav, hero, trust strip, final CTA, footer) only; everything behind auth stays light-only | Approved mockup pairs a dark cinematic hero (bridging the chrome/blue-neon logo) with light enterprise sections in the decided navy/brass/paper tokens; UI_Standards said "Light-only v1" | Force the hero into the light tokens (rejected: Ian approved the dark hero; it is the brand's front door) | Six dark marketing tokens added to `app.css` `@theme` + `UI_Standards.md` token table; door 7 (no `dark:` variants) unchanged — the hero uses tokens, not dark-mode variants | [D] |
| 2026-10-07 | 003-D02 | Fortify redirect targets: login → `/`, logout → `/`, register → `/login` (with verification flash) until the app home ships | `fortify.redirects` is all-null, defaulting to the nonexistent `/home` — successful login/registration would redirect to a 404 | Register a minimal `/home` now (rejected: the app home deserves its own spec, not a placeholder route) | `config/fortify.php` redirects set in T-05; superseded by the app-home spec when it lands | [P] |
| 2026-10-07 | Mockup approval | **Ian Bruce approved the spec 003 mockup** (`03-site-mockup.html` + six desktop/phone screenshots): dark cinematic hero with the chrome wordmark → light enterprise sections in the decided navy/brass/paper tokens; enterprise positioning for law firms, corporate legal departments, and government agencies | Planning protocol C-11 mockup-approval gate (DRAFT → APPROVED) before `06-plan.md` | — | Mockup is frozen: a UI change during build means editing the mockup and getting re-approval first. Approved deviations are tracked in `05-ui.md` | [D] |

## Open items

| Date | ID | Question | Stop condition | Status |
|---|---|---|---|---|
| 2026-10-07 | 003-O1 | Mockup's "Organization name" field + "creates your workspace / first administrator" copy contradict the 001 backend (`CreateNewUser` never creates an org; it joins the invitation org or the oldest open-registration org as least-privileged role) | Ian decides before T-04: (a) drop the field and adjust the copy (spec default), or (b) extend the backend to create an org + first admin (new contract work). Builder does not invent org creation | [O] |
