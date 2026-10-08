# 006 — Matter model and lifecycle — Strategic brief

**Status:** APPROVED
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## Goal

An attorney can run a matter's full lifecycle — create it, staff it, track parties and activity, and transition its state — with every action authorized at the matter level and written to the tamper-evident audit trail.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Matter CRUD: required title/type/client name, auto-generated unique `matter_number`, description; `matter_number`/`created_at` immutable; soft delete recoverable by org admins within 30 days | `test_matter_crud` — create → number matches `MAT-YYYY-NNNN` and is unique per org; update title; PATCH `matter_number` → 422/ignored; soft delete hides from list; org-admin restore within 30d works, after 30d → 422 | MAT-01 |
| C-02 | Lifecycle state machine with guarded transitions; invalid transition → 409 with legal next states; every transition audited with actor, from/to, note; concurrent transitions serialized | `test_lifecycle_transition_table` — walks every defined transition green; `INTAKE → DISCOVERY` → 409 with body listing `["ACTIVE","CLOSED"]`; two concurrent transitions → one wins, no lost update | MAT-02 |
| C-03 | `INTAKE → ACTIVE` requires at least one `client` party | `test_intake_to_active_requires_client_party` — transition without client party → 422 `client_party_required`; add client party → 200 | MAT-02, MAT-03 |
| C-04 | Parties and contacts per matter (6 types), soft delete | `test_party_crud` — add one of each type with email/phone/address/optional user link; soft delete hides from list but row survives | MAT-03 |
| C-05 | Received/sent document log, append-only (core fields immutable; corrections as new rows) | `test_document_log_append_only` — no update/delete routes exist; direct PATCH → 404/405; correction creates second row; annotations editable | MAT-04 |
| C-06 | Comments: markdown, one-level threading, author edit within 24h (edited badge), soft delete → tombstone, @mentions recorded | `test_comment_thread_rules` — reply-to-reply → 422; edit at 25h → 422; delete → tombstone renders; @mention of assigned user writes `comment.mentioned` audit | MAT-05 |
| C-07 | Immutable activity feed derived from `audit_events` (no separate table): newest-first, filterable by type/actor, paginated | `test_activity_feed` — create/transition/comment/assign each appear exactly once; `?type=` and `?actor=` filters work; page 2 disjoint from page 1 | MAT-06 |
| C-08 | Per-matter assignment via `matter_grants`; only owner/org admin assigns; last-owner protection; "My Matters" scoping; unassigned access → 403 without existence leak | `test_assignment_rules` — demote/remove final owner → 422 `last_owner`; non-admin list shows only granted matters; unassigned user GET → 403, title not leaked | MAT-07 |
| C-09 | Outside counsel sees only explicitly granted matters; cannot enumerate org directory or admin screens; actions audit-marked `external` | `test_outside_counsel_scoping` — OC granted matter A: matter B → 404; `/admin/*` → 403/404; audit rows carry `external: true` | MAT-07, MAT-08 (grant mechanism; 72h token flow is Phase 6) |
| C-10 | Matter list: trigram search across title/number/client/party names + structured filters (state, type, assignee, client, updated-since) AND-combined; permission-scoped before pagination | `test_matter_search` — "Apex" matches title and party name; `state=ACTIVE&type=litigation` ANDs; ungranted matters excluded from results entirely | MAT-15 |
| C-11 | Computed status summary derived on read (no driftable status table): state + days in state, doc-log received/sent counts, open/overdue tasks (0 until Phase 4), deadlines next 14d (empty until Phase 4), unread comments, last activity + actor, team list | `test_status_summary` — summary recomputes after a comment is added (no stale cache); `days_in_state` correct across a transition | MAT-16 |
| C-12 | Related-matter links: bidirectional, typed, self-link rejected; restricted matters render as "restricted matter" with no title/metadata leak | `test_related_matters` — link A↔B appears on both; self-link → 422; user without grant on B sees "restricted matter", no title | MAT-17 |
| C-13 | Every new endpoint × every role authorization matrix; `require_matter_access` before any data access; leak sentinels | `test_authorization_matrix` — all 006 routes × {org_admin, attorney, paralegal, viewer, outside_counsel, unassigned, other-org, anonymous} → expected 200/403/404; sentinel strings absent for denied actors | MAT-22 |
| C-14 | Guided closure: pre-close validation (closing note required), transition to CLOSED; closed matters read-only except reopen/transition | `test_guided_closure` — close without note → 422; close → CLOSED; POST comment on CLOSED → 409 `matter_closed`; reopen → ACTIVE | MAT-19 (closure mechanics; auto retention-hold creation is Phase 5) |

## Blueprint anchors

- [D] PRODUCT_BLUEPRINT §4 — the matter is the root container and authorization boundary; lifecycle `INTAKE → ACTIVE → DISCOVERY → PRE_TRIAL → TRIAL_SETTLEMENT → CLOSED → RETENTION_HOLD → DISPOSITION`; 404-not-403 denial semantics.
- [D] 001-D02 — matter-scoped access via `matter_grants` (REUSE for assignment; no new grants table).
- [D] 001-D03 — minimal `matters` stub; this spec extends it. 006-D02 supersedes the "never edits these columns" constraint for `status` (replaced by `lifecycle_state`; backfilled).
- [D] Audit append-only via DB trigger (001) — all 006 mutations write through `AuditLogger`.
- [D] UI_Standards [D] — all new views use `<x-ui.*>`; the `x-ui.status` `matter` map is extended with the lifecycle states.
- [O] None load-bearing. Template definitions (MAT-09–11) are deferred to Phase 4 with CAL-16–17 — recorded as a non-goal, not an open question.

## Security classification

- **Actors:** org_admin, attorney, paralegal, viewer, outside_counsel (explicit grant only), anonymous.
- **Data classes touched:** privileged (matter titles, party details, comments), work-product (notes, annotations), confidential (client names), internal (lifecycle metadata).
- **Allow events (audit):** `matter.created`, `matter.updated`, `matter.deleted`, `matter.restored`, `matter.transition`, `matter.closed`, `matter.reopened`, `matter.party.added`, `matter.party.removed`, `matter.comment.added`, `matter.comment.edited`, `matter.comment.deleted`, `matter.mentioned`, `matter.assigned`, `matter.unassigned`, `matter.link.added`, `matter.link.removed`, `matter.document_logged`.
- **Denial events (audit):** `matter.access.denied` (existing middleware), `matter.transition.denied`, `matter.assign.denied`, `matter.delete.denied`.
- **Leak sentinels:** `Sterling v. Apex Construction`, `Harborview Lease Dispute`, `Meridian Internal Review` must never appear in HTML/JSON/logs for actors without a grant; opposing-party names; cross-org matter titles.

## Evidence and fixtures

Canonical fixtures in `04-fixtures.json` (single definition, loaded by the extended `FixtureLoader`): three synthetic matters across lifecycle states, six users spanning roles and grants (incl. cross-tenant adversary, unassigned viewer, outside counsel with a single-matter grant, expired grant), parties of all six types, a threaded comment set (incl. a 25-hour-old comment and a tombstone), bidirectional matter links, and document-log rows in both directions. All data synthetic — the "Sterling v. Apex Construction" naming convention continues from earlier mockups.

## Pre-mortem

- **Likely failure:** the lifecycle transition table will be the first thing argued about — legal practice has more edge states (e.g. "settled but not closed") than eight states capture. Mitigation: the transition table is data in the contract, reviewed before tickets; adding a transition later is a migration-free contract change.
- **Assumption:** trigram search meets p95 < 500ms at 10k matters. Might be false without tuning. Mitigation: GIN indexes specified in the schema delta; a 10k-row seeded perf assertion in T-04 (allowed to be marked slow, not skipped).
- **Unknown:** whether `matter.access` middleware action levels (`comment`, `edit`, `manage`) compose cleanly with the existing `AccessControl::authorize` — T-05's matrix test will prove it.
- **Mitigation (scope):** anything touching documents-as-objects, deadline computation, tasks, holds, or share links is a defined interface + deferred ticket, not silent scope creep — see Non-goals.

## Non-goals

- Document objects, versioning, previews, OCR (Phase 3). The received/sent log here is a register, not storage.
- Deadline rules and computation (Phase 4). `deadlines next 14d` in the summary is an empty section with a defined interface until then.
- Task objects and MAT-18 linkage mechanics (Phase 4). "Blocked while open tasks exist" is a contract rule; enforcement lands with tasks.
- Retention-hold mechanics, the nightly expiry job, four-eyes disposition (Phase 5). The `RETENTION_HOLD`/`DISPOSITION` states exist; their flows do not.
- Share links internal/external (Phase 6). The 72-hour outside-counsel token flow (MAT-08 remainder) is Phase 6.
- Matter template definitions and application (MAT-09–11, Phase 4 with CAL-16–17). `template_id`/`template_version` columns are reserved as the interface.
- Notification delivery (Phase 4 pipeline). @mentions are parsed and recorded as activity + audit here.
- Matter merge, duplicate detection, real-time collaboration.

## MAT-23 anomaly (recorded, not invented)

The source material references a `MAT-23` touchpoint, but `docs/RFI_REQUIREMENTS_MATRIX.md` contains no `MAT-23` line item (MAT-01–MAT-22 only). Noted here so the gap is visible; nothing has been invented to fill it.

## Approval gate

- [ ] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [x] Product owner approved — **approver:** Ian Bruce · **date:** 2026-10-07
