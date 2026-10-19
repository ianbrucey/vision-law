# 009 — Plan

**Spec:** 009 — Legal calendaring, tasks, reminders, and deadline templates
**Inputs:** `00-brief.md` (APPROVED) · `01-archaeology.md` · `02-council.md` · `04-fixtures.json` · `decisions.md`
**Rule:** tickets run backend-out, in order, in the batches below. A ticket is done only when its named verdicts are green **and** Pint + PHPStan + the full suite pass. Never weaken an assertion to get green (commandment 5). Tests run in the sim clone (`/tmp/ci-sim`, host PHP + `vision_law_test` on :5435) during the loop; the container CI simulator is the authoritative gate at batch boundaries and at Freeze.

## Batch 1 — Core schema, items, agenda (C-01, C-02, C-03, C-04, C-05)

### T-01 — Schema + models + restricted flag + fixtures loader
- Migrations: `matters.restricted` (bool, default false); `calendar_items`; `item_attendees`; `calendar_filter_preferences`. Models: `CalendarItem`, `ItemAttendee`. `AccessControl` + `Matter::scopeAccessibleBy` restricted branch (009-D04). `config/calendaring.php` (email_enabled flag, digest defaults).
- `FixtureLoader::loadCalendaringFixtures()` + runner types: organization/user/matter (with `restricted`, nested grants)/grant/calendar_item (with attendees, checklist + dependencies resolved in T-04 — loader stores raw refs; T-01 loads base fields only)/reminder_preference/calendar_feed/guest_share/deadline_template (versions; content stored verbatim).
- Seed command skeleton: `calendaring:seed-data` (holiday packs + demo template install) — data files already in `database/data/holiday-calendars/`; holiday tables arrive in T-11, so the command's pack loading lands there; T-01 ships the command with template install only. *(If simpler: defer the whole command to T-11/T-19; do not half-ship it.)*
- **Verdicts seeded:** `test_calendaring_items_inherit_matter_authorization` (direct-ID GET on unauthorized/restricted/cross-org items → 404/no content; restricted matter invisible to grantless org admin; grant restores access) — file `tests/Feature/CalendaringAuthorizationTest.php`.

### T-02 — Item service + CRUD + lifecycle + provenance + concurrency
- `App\Services\Calendaring\ItemService` (create/update/transition/supersede/explain), form requests, `CalendarItemController` (store/show/update + confirm/complete/cancel/reopen actions), routes per council contract, minimal Blade (item show with explanation block, quick-add + editor forms on the matter calendar page — full agenda UI is T-03).
- **Verdicts:** `test_calendar_item_crud_validates_and_conflicts` (C-03), `test_item_lifecycle_never_rewrites_history` (C-04), `test_hard_deadline_edit_requires_reason_and_is_explainable` (C-05) — file `tests/Feature/CalendaringItemsTest.php`.

### T-03 — Agenda + matter calendar + filters + leak surface
- `AgendaQuery` service (authorized scope via accessibleBy + AccessControl semantics; day/week/month/list windows; changed-state surfacing with before/after from audit), `CalendarController@index` (agenda page), matter calendar tab (`/matters/{matter}/calendar`), filter persistence (009-D26), search/count integration: agenda JSON endpoint + counts respect the identical scope; changed/newly-assigned/unconfirmed/canceled/superseded items render with distinct status chips + text labels.
- **Verdicts:** `test_unified_agenda_combines_items_across_authorized_matters_only` (C-02, `CalendaringAgendaTest.php`); `test_no_existence_leak_via_search_counts_export_or_feed` (C-01 second half — export/feed endpoints arrive in T-14; this ticket pins search + counts + agenda paths, T-14 extends the same test file with export/feed assertions).

## Batch 2 — Tasks + recurrence (C-06, C-07, C-08)

### T-04 — Tasks: assignment, workflow, triage, dependencies, checklists
- Migrations: `task_dependencies`, `checklist_items`. `TaskService` (assign/reassign + notifications via ledger-lite in-app rows — full reminder pipeline is T-07; T-04 writes Laravel database notifications directly through a small `TaskNotifier` that T-07's DeliveryService later subsumes for reminder-class notices; task assignment notices may stay on TaskNotifier if the C-06 verdict only needs both parties notified in-app). Cycle detection, `previewImpact`/`applyMove`, checklist gate + override.
- **Verdicts:** `test_task_assignment_status_and_triage` (C-06), `test_dependency_cycle_rejected_and_checklist_gate_enforced` (C-07) — `tests/Feature/CalendaringTasksTest.php`.

### T-05 — Recurrence
- `RecurrenceService` (materialize 12 months, idempotent), edit-scope endpoints (`this|following|series`), series UI affordances minimal.
- **Verdict:** `test_recurrence_edit_scopes` (C-08) — `tests/Feature/CalendaringRecurrenceTest.php`.

## Batch 3 — Reminders (C-09, C-10, C-11, C-12)

### T-06 — Reminder policies + materialization + re-resolution
- Migrations: `reminder_policies`, `reminder_deliveries`, `reminder_preferences` (if not in T-01 — put preferences here), `notifications` table. `ReminderPlanner` (materialize deliveries from active policy of confirmed items; supersede pending on policy/item-date change; sent immutable).
- **Verdict:** `test_reminder_offsets_recalculate_on_date_change` (C-09) — `tests/Feature/CalendaringRemindersTest.php`.

### T-07 — Delivery pipeline
- `SendReminderDelivery` job (atomic claim, send-time recipient re-resolution + authorization recheck), channels: `InAppChannel` (ReminderNotification + inbox page `/notifications`), `EmailChannel` (ReminderMail; config-gated per 009-D08; redacted body per 009-D17). `DeliveryService` facade used by planner/notices. `reminders:dispatch` command + scheduler entry.
- **Verdict:** `test_reminder_delivery_resolves_recipients_and_ledgers_attempts` (C-10).

### T-08 — Reminder behaviors
- Immediate notices (assignment/material-change/cancel/confirm with before/after + actor), digest batching, ack/snooze endpoints + inbox UI, escalation sweep (`reminders:escalate` command + schedule), quiet-hours/org-floor enforcement, redaction sentinel assertions.
- **Verdict:** `test_reminder_preferences_escalation_and_redaction` (C-11).

### T-09 — Reliability + ops view
- Backoff/dead-letter/replay (DeliveryService), admin ops view (`/admin/reminders`, RequireOrgAdmin) with failure states + unconfigured-email visibility, fault-injection test hooks (a test-only mail channel double that times out after "sending", worker-restart simulation = re-dispatch of claimed rows after lease expiry — claimed rows older than the lease return to pending in dispatch).
- **Verdict:** `test_reminder_pipeline_is_idempotent_under_failure` (C-12).

## Batch 4 — Templates + engine (C-13, C-14, C-15, C-16)

### T-10 — Template catalog + lifecycle
- Migrations: `deadline_templates`, `deadline_template_versions`. `TemplateService` (CRUD, submit/publish/retire, maker-checker per org setting, checksum + immutability), catalog + builder UI (search by jurisdiction/court/matter type/effective date).
- **Verdict:** `test_template_lifecycle_and_immutable_published_versions` (C-13) — `tests/Feature/CalendaringTemplatesTest.php`.

### T-11 — Deadline engine + holiday packs
- Migrations: `holiday_calendars`, `holidays`. Pack loader (from `database/data/holiday-calendars/*.json`, versioned, checksum). `DeadlineEngine` (DSL per 009-D19: DAG topological evaluation, business/court day counters over pack holidays, adjust ops, cutoff, trigger-day-excluded, full trace). Golden cases from fixtures (`golden_cases` runner type or read the JSON directly in the test).
- **Verdicts:** `test_deadline_engine_golden_cases_and_replay` (C-15) — `tests/Feature/CalendaringEngineTest.php`; engine half of C-18 (date-only zone stability) asserted here or in T-15.

### T-12 — Instantiation
- Migration: `template_instantiations`. `InstantiationService` (preview = engine over template graph → warnings; confirm = single transaction, idempotency key, provenance stamping, matter template stamp), preview/confirm UI (preview graph with per-item explanation).
- **Verdict:** `test_template_instantiation_is_previewed_transactional_and_provenanced` (C-14).

### T-13 — Recalculation
- Migration: `recalculations` (or on instantiation — separate table per council D21). `RecalculationService` (propose from trigger/rule-pack/holiday-pack change; impact diff; approve all/selected; supersede + override preservation + reminder re-resolution + notifications).
- **Verdict:** `test_recalculation_requires_impact_preview_and_preserves_overrides` (C-16).

## Batch 5 — External, time zones, audit, home, mobile (C-17, C-18, C-19, home module, C-20)

### T-14 — External coordination (Phase A)
- Migrations: `guest_shares`, `calendar_feeds`. `IcsService` (export/feed/invite REQUEST/UPDATE/CANCEL with UID + SEQUENCE), `GuestShareService`, routes: export, feed issue/revoke/fetch, guest view; invite mail (Mail::fake in tests).
- **Verdicts:** `test_guest_coordination_scope_and_revocation` + `test_ics_invite_update_cancel_lifecycle` (C-17) — `tests/Feature/CalendaringExternalTest.php`; extend the C-01 leak test with export/feed paths.

### T-15 — Time-zone end-to-end
- Cross-zone display assertions (NY-created event rendered for an LA viewer = same instant), ICS export instant check, date-only deadline zone stability, DST-boundary reminder fires exactly once (dispatch under a controlled clock — Carbon::setTestNow + travel helpers; queue sync in tests).
- **Verdict:** `test_timezone_and_dst_consistency` (C-18) — `tests/Feature/CalendaringTimezonesTest.php`.

### T-16 — Audit completeness
- Walk the brief's action list; any missing AuditLogger call lands here with the event names from 009-D24; append-only proof (update/delete attempts throw at model + DB trigger level — reuse 001 pattern assertions).
- **Verdict:** `test_calendaring_audit_is_append_only_and_complete` (C-19) — `tests/Feature/CalendaringAuditTest.php`.

### T-17 — Home deadlines module + chrome
- HomeController assembly + `home.blade.php` card per 009-D25; nav gains a Calendar entry (chrome defaults — keep 010 C-05 assertions green: they enumerate specific pages, adding a nav link is compatible); inbox link surfaces unacked count.
- **Verdict:** covered by C-02/C-09-adjacent assertions + a home-module test in `CalendaringAgendaTest` (module shows only authorized upcoming items; hidden for zero-access user). 010 suite must stay green unmodified.

### T-18 — Mobile gate
- `tests/Feature/CalendaringMobileTest.php` mirroring `AppHomeMobileTest` technique: agenda, matter calendar list view, quick-add, confirm flow at 390px; no overflow; actions reachable without hover; non-color status cues present (text labels).
- **Verdict:** `test_mobile_agenda_gate` (C-20).

## Batch 6 — Runtime + freeze

### T-19 — Production runtime (O-07)
- systemd units `visionlaw-worker.service` + `visionlaw-scheduler.service` installed on the server (unit files also committed under `deploy/systemd/` for the record), enabled + started; live verification: create a due reminder via tinker on the **test** path? No — live verification = dispatch a real in-app reminder for a fixture-free synthetic check: use a throwaway user/matter created and removed via tinker on the live DB is a WRITE to the live DB — forbidden spirit (commandment 8 covers migrate/seed; creating live data is also out). Verify instead: units active, queue processes jobs (push a no-op framework job / the dispatch command runs clean against prod), scheduler lists `reminders:dispatch`, logs clean. State precisely what was and wasn't proven live in the freeze.
- Seed: run `calendaring:seed-data` on prod post-merge (idempotent packs + demo template into existing orgs) — orchestrator step after parent merges; record as a follow-up in freeze if it must wait for the merge.

### T-20 — Freeze
- `99-freeze.md` (per-claim verdict table, gate totals host + sim, decisions recap, deviations), dev-journal entries, this spec's schema folded into the plan's contract notes. Full container sim run green is the freeze gate.

## Batch gate rule

After each batch: named verdicts green + full suite green (host run in sim clone) + Pint + PHPStan. A red gate stops the line — fix or report, never route around.
