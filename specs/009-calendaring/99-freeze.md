# Spec 009 — Calendaring — Freeze Record

**Branch:** `feat/009-calendaring` · **Base:** main @ `8b34ac3` (approved brief) · **Freeze head:** `957adbb` + this freeze commit
**Brief:** approved by Ian Bruce 2026-10-19. Blueprint anchors: Legal Calendaring blueprint v1.0 (CAL/TASK/REM/TMP/SYNC/SEC/AUD/NFR/QA).
**Verdict: all 20 claims PASS.** No claim was blocked; no brief contradiction required escalation.

## Per-claim verdicts

| Claim | Verdict | Named test (all in `tests/Feature/Calendaring*Test.php`) |
|---|---|---|
| C-01 Authorization | PASS | `test_calendaring_items_inherit_matter_authorization` |
| C-02 Item CRUD | PASS | `test_calendar_item_crud_validates_and_conflicts` |
| C-03 Lifecycle | PASS | `test_item_lifecycle_never_rewrites_history` |
| C-04 Agenda, filters, no existence leak | PASS | `test_unified_agenda_combines_items_across_authorized_matters_only`, `test_no_existence_leak_via_search_counts_export_or_feed` |
| C-05 Hard-deadline edit protocol | PASS | `test_hard_deadline_edit_requires_reason_and_is_explainable` |
| C-06 Tasks | PASS | `test_task_assignment_status_and_triage` |
| C-07 Dependencies + checklist gate | PASS | `test_dependency_cycle_rejected_and_checklist_gate_enforced` |
| C-08 Recurrence | PASS | `test_recurrence_edit_scopes` |
| C-09 Reminder policies + re-resolution | PASS | `test_reminder_offsets_recalculate_on_date_change` |
| C-10 Delivery pipeline + ledger | PASS | `test_reminder_delivery_resolves_recipients_and_ledgers_attempts` |
| C-11 Reminder behaviors | PASS | `test_reminder_preferences_escalation_and_redaction`, `test_item_confirm_cancel_and_material_date_notices_use_the_ledger` |
| C-12 Reliability under failure | PASS | `test_reminder_pipeline_is_idempotent_under_failure` |
| C-13 Template catalog + maker-checker | PASS | `test_template_lifecycle_and_immutable_published_versions` |
| C-14 Instantiation | PASS | `test_template_instantiation_is_previewed_transactional_and_provenanced` |
| C-15 Deadline engine | PASS | `test_deadline_engine_golden_cases_and_replay`, `test_holiday_packs_load_idempotently_and_drive_business_day_math`, `test_engine_offset_rules_match_the_step_rules` |
| C-16 Recalculation | PASS | `test_recalculation_requires_impact_preview_and_preserves_overrides` |
| C-17 External coordination (Phase A) | PASS | `test_guest_coordination_scope_and_revocation`, `test_ics_invite_update_cancel_lifecycle` |
| C-18 Timezones + DST | PASS | `test_timezone_and_dst_consistency` |
| C-19 Audit | PASS | `test_calendaring_audit_is_append_only_and_complete` |
| C-20 Mobile gate (390px) | PASS | `test_mobile_agenda_gate` (runs in the container sim; headless Chromium) |

Home deadlines module (009-D25, plugs into spec 010 `/home` per 010-D08): `test_home_deadlines_module_shows_only_authorized_upcoming_items` — PASS; spec 010 suite unmodified and green.

## Gates

- **Container CI simulator (authoritative):** **278 passed / 0 failed / 0 errors / 0 skipped, 7,740 assertions** (TEST_RC=0). Reconciles exactly: 252 baseline + 26 new calendaring tests; all Chromium tests run in the container.
- **Host (sim clone, PHP 8.4, `vision_law_test`):** 277 passed / 1 skipped, 7,702 assertions. The single host skip is a pre-existing host-environment skip (a Chromium-dependent test that runs fully in the container sim, hence 278/0-skipped there).
- **Pint:** PASS (clean, `--test`). **PHPStan level 7:** OK, no errors, **no baseline file** (none exists; none added).
- Spec 005 suite, spec 010 tests, and the 2FA exemption behavior: unmodified.

## Council resolutions (see 02-council.md / decisions.md)

- **Split (009-D01):** 009A/009B adopted as internal sequencing only; both halves delivered in this run.
- **O-01 [D]:** Phase A external coordination only (ICS export/feed, guest view, invites); SYNC-05–08 two-way sync deferred to an integrations spec.
- **O-02 [D]:** rule content ships as DEMONSTRATION packs with an `owner` field; first context Georgia state-court civil (demo). Nothing is asserted legally correct.
- **O-03 [D]:** archaeology found the 006 matrix has **no** ethical wall for org admins; resolved as a recorded EXTEND — opt-in `matters.restricted` flag (default off), audited toggle, default-off preserves all 006 verdicts.
- **O-04 [D]:** holiday calendars are versioned in-repo data packs (`database/data/holiday-calendars/*.json` → `calendaring:load-holiday-packs`); v1 packs `us-federal-2026`, `ga-state-2026` (DEMONSTRATION).
- **O-05 [D]:** hard deadlines + escalations are never digested or quieted; the org floor cannot be configured away.
- **O-06 [D]:** blueprint p95/availability NFRs are aspirations, not claims; RPO ≤15min / RTO ≤4hr are infrastructure commitments outside this spec — not accepted as app claims.
- **O-07 [D]:** runtime installed (below); email coded behind `CALENDARING_EMAIL_ENABLED` (default false), unconfigured attempts ledger as `failed_unconfigured`; in-app is the guaranteed channel.

## Runtime (T-19)

- `visionlaw-worker.service` and `visionlaw-scheduler.service` installed in `/etc/systemd/system/`, **enabled and active** (verified 2026-10-19 ~21:28Z, running against `/opt/vision-law`). Unit sources ship in-repo at `deploy/systemd/`. Worker runs `queue:work database --tries=1 --max-time=3600` (retry/backoff lives in the delivery ledger, 009-D16; hourly recycle).
- The units run the live checkout, which gains the 009 code when this branch merges and the poller deploys; reminder dispatch/escalation commands were exercised green against the test DB throughout (C-10/C-12).
- **Known ops gap:** `deploy.sh` restarts only `visionlaw-web`; worker/scheduler are not restarted on deploy. Mitigated by the worker's hourly `--max-time` recycle; scheduler code changes need a manual `systemctl restart visionlaw-scheduler` after the merge deploy.
- **Post-merge step (for the merger):** run `php artisan calendaring:load-holiday-packs` once against the live app so the engine's packs exist in production tables. No demo templates are seeded into live orgs by this spec; template creation is an in-app, per-org action.

## Deviations & notes (all recorded in dev-journal.md)

1. **C-11 fixture fidelity:** `04-fixtures.json` `pref-quiet` pins quiet 22:00–07:00 with digest off (its purpose: prove mandatory notices bypass quiet hours). The C-11 test's digest leg requires digest on, so the test sets its own preference values (quiet 21:00–07:00, digest on) rather than loading the fixture record verbatim. Behavior verified is identical in kind; recorded here so the fixture/test divergence is explicit, not silent.
2. **Notifications table** (standard Laravel) was pulled forward from T-06 into T-04 because C-06 assignment notifications needed it.
3. **Migration numbering** follows the repo's actual `0100NN` sequence rather than the plan's sketched numbers.
4. **Recurrence conventions:** the series anchor counts as the first occurrence; "this"-scope edits detach via `recurrence_detached` while keeping `series_id` provenance.
5. **Assignment notices** route through the delivery ledger (NoticeService) as the single system of record; C-06 assertions unchanged and green.
6. Two intermediate batch gate reports were initially misstated by workers (parallel test runs against the shared test DB corrupted totals) and were self-corrected; every figure in this freeze was re-verified by the orchestrator via named-test runs and reconciled suite arithmetic.

## Follow-ups (not blockers)

- Post-merge: `calendaring:load-holiday-packs` on live (above); restart `visionlaw-scheduler` after the merge deploy.
- Email transport remains unconfigured by design (O-07); enabling it is a config change + `CALENDARING_EMAIL_ENABLED=true`.
- Rule packs remain DEMONSTRATION content until a qualified content owner signs off (O-02).
