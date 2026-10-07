# <NNN> — <Feature name> — Decision log

> One home per fact. Record every decision made during this feature's life —
> at brief, archaeology, contract, implementation, review, and freeze — so a
> future agent can reconstruct WHY, not just what. Formal decisions live here;
> the dev journal links here rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| <YYYY-MM-DD> | <NNN>-D01 | <what was decided> | <why it came up> | <what else was on the table> | <what it commits us to> | [D] decided / [P] proposed / [O] open / [S] superseded |

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| <YYYY-MM-DD> | <NNN>-D01 | <example: "Version restore creates N+1 rather than mutating history"> | <brief claim C-02 required audit-safe rollback> | <in-place restore; copy-on-write pointer swap> | <storage grows monotonically; audit stays trivially consistent> | [D] |

<RULES:>
- Tag each decision [D]/[P]/[O] using the same meanings as the system docs.
- A decision that reverses an earlier one marks the old row [S] superseded and links the new ID — never delete or rewrite history.
- Decisions that change a governing system document (blueprint, matrix, drafting architecture) are noted here AND proposed as an edit to that document — the system doc, not this log, is the authority.
- Open [O] items list their stop condition: what waits on them.
