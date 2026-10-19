# 009 — Decisions

Running record for spec 009 (legal calendaring). Tags per the Planning
Protocol: [D] decided / [P] proposed / [O] open. Full reasoning in
`02-council.md`. The brief's open decisions O-01…O-07 are all resolved
below; no [O] items remain.

| ID | Tag | Decision |
|---|---|---|
| 009-D01 | [D] | Split adopted as internal sequencing only: core (C-01…C-12, C-18, C-19, C-20) builds before templates/engine/external (C-13…C-17); **both halves ship in this run** per Ian's whole-spec approval (2026-10-19). |
| 009-D02 | [D] | O-01 → Phase A external coordination only (ICS invites/export/feed + guest view). SYNC-05–08 deferred to the integrations phase. |
| 009-D03 | [D] | O-02 → rule content ships only as DEMONSTRATION packs with an `owner` field; first context = Georgia state-court civil litigation (demo). No pack is asserted legally correct; losing the label requires a future recorded decision naming a qualified owner. |
| 009-D04 | [D] | O-03 → opt-in ethical wall: `matters.restricted` (default false). When true, the org-admin org-default is skipped in `AccessControl` and `Matter::scopeAccessibleBy`; grants unchanged. Archaeology found **no** wall in 006 — this is a recorded EXTEND, default-off so all 006 verdicts are preserved. Toggle = matter `manage`, audited. |
| 009-D05 | [D] | O-04 → holiday calendars are versioned in-repo data packs (`database/data/holiday-calendars/*.json`) seeded into versioned tables; historical versions retained; v1 packs `us-federal-2026` + `ga-state-2026`, DEMONSTRATION-labeled. |
| 009-D06 | [D] | O-05 → hard deadlines and escalations are never digested and never quieted; digest covers noncritical updates only; org floor cannot be configured away. |
| 009-D07 | [D] | O-06 → blueprint NFR p95/availability recorded as aspirations, not claims. RPO/RTO are infrastructure commitments **outside this spec** — not accepted, not claimed. |
| 009-D08 | [D] | O-07 → install `visionlaw-worker` (queue:work) + `visionlaw-scheduler` (schedule:work) systemd units, verified live. Email channel behind `CALENDARING_EMAIL_ENABLED` (default false); unconfigured email attempts ledger as `failed_unconfigured` — loud, never fake-sent. In-app is the guaranteed channel. |
| 009-D09 | [D] | One `calendar_items` table for deadlines/events/tasks; satellite tables for attendees, dependencies, checklists. Single scoped query surface for agenda/search/counts/feeds. |
| 009-D10 | [D] | Item field set per council §D10 (lifecycle status, task workflow status, classification, jurisdiction, due_date + cutoff, UTC instants + origin zone, optimistic-concurrency `version`, supersede links, provenance incl. calc trace). |
| 009-D11 | [D] | Lifecycle law: service-only transitions; supersede links successor and preserves the original's facts byte-identical; only Confirmed items drive mandatory reminders/dashboards (blueprint D-03). |
| 009-D12 | [D] | Hard-deadline edits require a reason; every item has a renderable explanation from stored provenance (manual items record manual entry). |
| 009-D13 | [D] | Task law: single responsible assignee (nullable → triage), reassignment notifies both parties, finish-to-start dependencies with cycle rejection + preview-before-apply on predecessor moves, required-checklist completion gate with documented override. |
| 009-D14 | [D] | Recurrence: distinct occurrence rows per series; edit scopes this/following/series; past/completed occurrences never mutated; 12-month materialization window; idempotent per (series, date). |
| 009-D15 | [D] | Reminder policies are versioned per item (rules jsonb: relative offsets or absolute instants, channels, recipient specs, criticality). New version supersedes pending deliveries; sent deliveries are immutable. |
| 009-D16 | [D] | Delivery ledger is the unit of work: unique idempotency key + atomic claim + send-time recipient re-resolution and authorization recheck; per-channel rows; backoff 1m/5m/15m/1h/4h ×5 → dead-letter; admin replay audited. |
| 009-D17 | [D] | Reminder behaviors: immediate before/after notices; digest for noncritical only; ack + snooze (never past due without edit-rights override); escalation of unacknowledged mandatory reminders; quiet hours bounded by org floor; restricted-matter email is neutral + secure link, never facts. |
| 009-D18 | [D] | Templates: per-org `deadline_templates` + immutable published `deadline_template_versions` (checksum; edits fork a new draft); maker-checker publish when org policy requires (default on). Distinct from 007 document templates in model, routes, and names. |
| 009-D19 | [D] | Rule DSL is data: allowlisted ops (add_calendar_days / add_business_days / add_court_days / adjust / set_cutoff), DAG evaluation in topological order, trigger day = day 0, versioned holiday packs, full step trace stored per computed item; replay must be byte-identical. |
| 009-D20 | [D] | Instantiation: preview computes without writing; confirm is one transaction with a unique idempotency key; double-confirm returns the existing graph; generated items keep template/rule versions, inputs, and traces permanently; matter stamped with template id/version. |
| 009-D21 | [D] | Recalculation: impact preview first; authorized approval (all or selected) before any mutation; supersedes affected generated items; manual overrides preserved unless explicitly reset; reminders re-resolve; affected users notified. |
| 009-D22 | [D] | Phase A surfaces: ICS export (auth, scoped), ICS invites with stable UID + rising SEQUENCE, per-user revocable feed (hash-stored token, fetch-time re-filter, 404 on revoked), guest view over explicitly shared items/fields only; revocation immediate for web/feed/future reminders, ledger history retained. |
| 009-D23 | [D] | Time-zone law: timed items store UTC + origin IANA zone; legal due dates are dates in the jurisdiction zone and never shift by viewer; reminders resolve local date+time+zone to a single UTC instant (DST-safe, idempotency key includes it). |
| 009-D24 | [D] | Audit event set per council §D24, written only via AuditLogger; payload key names respect the privileged-substring blocklist (feed/share ids, never credential-shaped keys). |
| 009-D25 | [D] | Home deadlines module per 010-D08: upcoming confirmed items + overdue/7-day counts + unacked mandatory count over accessible matters only, rendered below Recent activity; hidden entirely for zero-access users (010 C-04 preserved). |
| 009-D26 | [D] | Agenda filters persist per user (`calendar_filter_preferences`), applied server-side on every agenda read. |

Inherited from the brief (not re-decided): blueprint v1.0 numbering is
authoritative (the georgia-rfi CAL-01–22 file is superseded for 009);
001 audit base and 006 authorization are consumed, not rebuilt; 002-D02
UI standards; 007-D04 mobile gate; 007 retention/hold — 009 builds no
purge path and calendar deletion follows matter retention/hold policy;
SMS/push are adapters-only non-goals; no AI anywhere in the date path.
