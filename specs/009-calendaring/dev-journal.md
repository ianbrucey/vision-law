# 009 — Dev journal

## 2026-10-19 — Archaeology + Council + Plan (orchestrator)

- Baseline at branch point (`8b34ac3`): Pint PASS (280 files), PHPStan level 7 OK, suite 252 green (CI simulator at parent commit; this commit is docs-only above it).
- Worktree strategy: build in the sim clone (`/tmp/ci-sim`) on `feat/009-calendaring`; the live checkout `/opt/vision-law` never leaves `main`. Host gates run from the sim clone (its vendor carries the dev tools the production install lacks); the container simulator is the authoritative gate at batch boundaries.
- Archaeology headline: 006 authorization, the audit chain, the queue, and the 010 home all reuse cleanly. The one real gap is O-03 — 006 has **no** ethical wall (org_admin is implicit owner on every matter). Council resolved it as a recorded, default-off EXTEND (`matters.restricted`, 009-D04) rather than pretending the matrix had one.
- Golden dates in `04-fixtures.json` were hand-computed and then re-checked against the shipped GA pack (Thanksgiving **and** the day after are pack holidays — two goldens were corrected before any code existed; this is why fixtures come first).
- Split (009-D01): core tickets first, templates/engine second, both delivered this run.

## Execution log (2026-10-19)

- **Batch 1** (`ddecaa3`, `9841519`, `21a4a77`) — T-01 schema + restricted flag + fixture loader; T-02 item service/CRUD/lifecycle/concurrency; T-03 agenda + matter calendar + persisted filters. C-01…C-05 green. Host: 257 passed / 1 skipped.
- **Batch 2** (`63f3931`, `6eda9e5`) — T-04 tasks/dependencies/checklists (notifications table pulled forward from T-06 for C-06); T-05 recurrence (anchor = first occurrence; `recurrence_detached` for "this"-scope edits). C-06…C-08 green. Host: 260 / 1.
- **Batch 3a** (`2d8195b`, `56ab7b2`) — T-06 policies + planner; T-07 delivery pipeline (atomic claim, send-time authorization recheck, ledger, channels behind container bindings). C-09, C-10 green. Host: 262 / 1.
- **Batch 3b** (`f020dab`, `b6eeef0`, fix `c6c63b6`) — T-08 behaviors via NoticeService (ledger = single system of record; quiet hours, digest, ack/snooze, escalation); T-09 ops view + replay + fault injection. C-11, C-12 green. The fix commit records two lessons: new Blades must use x-ui wrappers only (VisionUiTest doors), and admin controllers take string ids so the 005 setup-mode probe redirects before 404s. Host: 265 / 1.
- **Batch 4a** (`d01a3af`, `4d25b11`) — T-11 engine + holiday packs; T-10 template catalog with maker-checker. C-13, C-15 green; all six golden cases match, incl. service 2026-10-30 → answer 2026-11-30 → disclosures 2026-12-14 / discovery-open 2026-12-07. Host: 269 / 1.
- **Batch 4b** (`7ef8170`, `2ad3b44`) — T-12 instantiation (preview persists nothing; confirm recomputes server-side under an idempotency key); T-13 recalculation (impact preview, supersede-don't-rewrite, override preservation). C-14, C-16 green. Host: 271 / 1.
- **Batch 5** (`0c43d49`, `1879d43`, `5b77b91`, `3f7253a`, `957adbb`) — T-14 Phase A external coordination (ICS export/feed, guest shares, invites; leak test extended to export/feed); T-15 timezone/DST; T-16 audit completeness (added missing D24 emitters for instantiation/recalculation/restricted toggle); T-17 home deadlines module + Calendar nav; T-18 mobile gate. C-17…C-20 green. Host: 277 / 1.
- **Batch 6 / freeze** — `visionlaw-worker` + `visionlaw-scheduler` systemd units installed, enabled, active on the server (sources in `deploy/systemd/`). Container CI simulator (authoritative): **278 passed / 0 failed / 0 skipped, 7,740 assertions** = 252 baseline + 26 new calendaring tests. Pint clean; PHPStan level 7 clean, no baseline. Live checkout `/opt/vision-law` never left `main`; live DB never migrated/seeded by this work.
- Process note: two batches initially reported wrong suite totals (parallel runs against the shared test DB); both were self-corrected by the workers, and the orchestrator independently re-ran every named claim test. All freeze figures are from first-hand reads.
