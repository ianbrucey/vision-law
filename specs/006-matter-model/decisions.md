# 006 — Matter model and lifecycle — Decision log

> One home per fact. Formal decisions live here; the dev journal links here rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-07 | 006-D01 | `matter_number` format is `MAT-YYYY-NNNN` (unique per org per year) | Seeded data already uses `MAT-2026-001`; matrix says `YYYY-NNNN` — the MAT- prefix is the established convention | Bare `YYYY-NNNN` | Number generation, search, and all fixtures use the prefixed form | [D] |
| 2026-10-07 | 006-D02 | Replace `status` with `lifecycle_state`; backfill `open→INTAKE`; drop `status` | 001-D03's stub served its purpose; a dead column is worse than a clean migration. Only `Matter::$fillable` read `status` | Keep both columns (dead-column debt) | Supersedes 001-D03's "never edits" for this column ([S] on that row's scope) | [D] |
| 2026-10-07 | 006-D03 | Matter creation: attorney, org_admin, paralegal | Viewers and outside counsel must not create matters | Open creation for all authenticated users | Enforced in `MatterPolicy::create` / controller | [D] |
| 2026-10-07 | 006-D04 | Activity feed reads `audit_events` — no separate table | MAT-06 requires every mutation to write an audit row in the same transaction; a second table would drift | Dedicated `matter_activities` table | Feed is a read model; filter/pagination over the audit table | [D] |
| 2026-10-07 | 006-D05 | Assignment rides on `matter_grants` — no new table | 001-D02 built grants for exactly this; MAT-07's roles match the grant roles | Separate `matter_assignments` table | Last-owner protection lives in `MatterService`, enforced on the grant controllers | [D] |
| 2026-10-07 | 006-D06 | Transition to the current state → 200 no-op, no audit row | Avoids noisy no-op transitions and simplifies retry logic | 409 on same-state | Documented in the contract error catalog | [D] |
| 2026-10-07 | 006-D07 | Matter templates (MAT-09–11) deferred to Phase 4 with CAL-16–17 | Template definitions include task offsets and deadline-rule anchors — calendaring-owned concepts | Build template CRUD in 006 without tasks/deadlines | `template_id`/`template_version` columns reserved as the interface; no template UI in 006 | [D] |
| 2026-10-07 | 006-D08 | Notification delivery deferred to Phase 4 pipeline | Email is unconfigured; the durable pipeline is CAL-11–13 scope | Build a one-off mailer in 006 | @mentions parsed and recorded as activity + audit; delivery interface defined | [D] |
| 2026-10-07 | 006-D09 | MAT-23 anomaly: noted, not invented | Source references a MAT-23 touchpoint; the matrix has no MAT-23 line item | Invent a requirement | Recorded in the brief; nothing built for it | [D] |
| 2026-10-07 | 006-D10 | Number generation via `pg_advisory_xact_lock(org_id, year)` + MAX+1 | Gapless-enough without a counters table; race-safe | Counters table; UUID-based numbers (not human-friendly) | Documented retry behavior in the contract | [D] |

<RULES:>
- Tag each decision [D]/[P]/[O] using the same meanings as the system docs.
- A decision that reverses an earlier one marks the old row [S] superseded and links the new ID — never delete or rewrite history.
- Decisions that change a governing system document are noted here AND proposed as an edit to that document.
