# 006 — Matter model and lifecycle — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out. Model + service + authorization land before UI — the documents phase (003's successor) needs the matter API stable. Parallel workers get disjoint file ownership.

---

## Ticket 1 — Schema, models, number generation

**Files:** 6 new migrations (ordered per 02-schema-delta.md); `app/Models/{MatterParty,MatterComment,MatterLink,MatterDocumentLog,MatterCommentRead}.php`; `app/Models/Matter.php` (edit); `tests/Helpers/FixtureLoader.php` (edit: `loadMatterFixtures()`)
**Inputs:** 02-schema-delta.md, 04-fixtures.json
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `database/migrations/2026_10_07_0200*.php`; auth controllers/services

### Work
- [ ] Write and run the 6 migrations (matters ALTER with `open→INTAKE` backfill + `status` drop; 5 new tables)
- [ ] Models with fillable, casts, relations; `Matter::booted` touch behavior
- [ ] `MatterService::generateNumber()` — `MAT-YYYY-NNNN` per org per year under `pg_advisory_xact_lock`
- [ ] `FixtureLoader::loadMatterFixtures()` loads `04-fixtures.json` (all fixture cases incl. adversary)

### Acceptance criteria
- [ ] `test_matter_number_generation` — two concurrent creates → distinct sequential numbers, no collision
- [ ] `test_status_backfill` — existing `open` rows read as INTAKE
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 2 — Lifecycle transitions + closure

**Files:** `app/Services/MatterService.php` (create); `app/Http/Controllers/MatterController.php` (edit: store/update/destroy/restore/transition/close); `routes/web.php` (edit, orchestrator-owned section)
**Inputs:** 03-contract.md §Lifecycle transition table, §Error catalog
**Dependencies:** Ticket 1
**Forbidden edits:** grant controllers; auth surface

### Work
- [ ] CRUD + soft delete/restore (30-day window, org admin)
- [ ] `transitionMatter()` — table-driven guards, `SELECT … FOR UPDATE` serialization, 409 + `legal_next_states`
- [ ] `closeMatter()` — closing-note validation, CLOSED transition, `MatterClosed` event dispatch (no listener)
- [ ] CLOSED read-only enforcement (409 `matter_closed` on mutations)
- [ ] Every mutation writes its audit event with `$matter` in the same transaction

### Acceptance criteria
- [ ] C-01 `test_matter_crud` · C-02 `test_lifecycle_transition_table` · C-03 `test_intake_to_active_requires_client_party` · C-14 `test_guided_closure`
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 3 — Parties, comments, links, document log

**Files:** `app/Http/Controllers/{Party,Comment,Link,DocumentLog}Controller.php` (create); `routes/web.php` (edit)
**Inputs:** 03-contract.md §Routes, §Requests & validation
**Dependencies:** Ticket 1
**Forbidden edits:** `MatterService` transition logic (T-02 owns it)

### Work
- [ ] Party CRUD + soft delete; comment thread (one-level guard, 24h edit, tombstone, @mention parse → `matter.mentioned` audit); links (canonical ordering, self-link guard); document log (append-only, annotations mutable)
- [ ] Audit events for every mutation

### Acceptance criteria
- [ ] C-04 `test_party_crud` · C-05 `test_document_log_append_only` · C-06 `test_comment_thread_rules` · C-12 `test_related_matters`
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 4 — Assignment, search, status summary

**Files:** `app/Http/Controllers/AssignmentController.php` (create); `app/Http/Controllers/MatterGrantController.php` (edit: delegate to service); `app/Services/MatterService.php` (edit: assignment/search/summary)
**Inputs:** 03-contract.md §Domain service methods (`assignUser`, `searchMatters`, `statusSummary`)
**Dependencies:** Tickets 1–2
**Forbidden edits:** grant table schema (REUSE as-is)

### Work
- [ ] Assignment with last-owner protection (`last_owner` → 422); grant expiry honored
- [ ] "My Matters" scoping in index query; trigram search + AND filters; permission scoping before pagination; seeded 10k-row perf assertion (may be marked slow)
- [ ] `statusSummary()` derived on read; `markTimelineRead()` for unread counts
- [ ] `GET /matters/{matter}/summary` JSON endpoint

### Acceptance criteria
- [ ] C-08 `test_assignment_rules` · C-09 `test_outside_counsel_scoping` · C-10 `test_matter_search` · C-11 `test_status_summary`
- [ ] Architecture suite + Pint + PHPStan green

---

## Ticket 5 — UI screens

**Files:** `resources/views/matters/**` (create); `app/View/Components/Ui/Status.php` (edit: lifecycle states); `docs/UI_Standards.md` (edit at freeze)
**Inputs:** `05-ui.md`, approved `05-ui-mockup.html`
**Dependencies:** Tickets 2–4
**Forbidden edits:** `resources/views/components/ui/*` primitives (map extension only)

### Work
- [ ] List (search, filter chips, table, empty state), detail with 4 tabs (Documents tab = explicit Phase-3 placeholder), new/edit form, transition modal, denied page
- [ ] Replace the `matter` status map with the 8 lifecycle states + tones

### Acceptance criteria
- [x] Empty/validation/denied/destructive states render per 05-ui.md; mockup-vs-built screenshot comparison at 1440/390 — VERIFIED AT FREEZE 2026-10-07 (11 UI tests + 24 headless-Chromium screenshots, 1440px + 390px; all states match mockup)
- [ ] `npm run build` succeeds; architecture suite + Pint + PHPStan green

---

## Ticket 6 — Authorization matrix + activity feed

**Files:** `app/Services/AccessControl.php` (edit); `app/Http/Middleware/RequireMatterAccess.php` (edit); `tests/Feature/MatterAuthorizationMatrixTest.php` (create)
**Inputs:** 03-contract.md §Role-visible data, §Error catalog; 00-brief.md leak sentinels
**Dependencies:** Tickets 2–4

### Work
- [ ] New action levels (`comment`, `edit`, `manage`) in middleware + `AccessControl`
- [ ] Matrix test: every 006 route × {org_admin, attorney, paralegal, viewer, outside_counsel, unassigned, other-org, anonymous} → expected 200/403/404
- [ ] Leak sentinels: sentinel strings absent from HTML/JSON/logs for denied actors
- [ ] Activity feed endpoint behavior (reads `audit_events`, filterable, paginated) — C-07 verdict

### Acceptance criteria
- [ ] C-07 `test_activity_feed` · C-13 `test_authorization_matrix`
- [ ] Full suite green

---

## Ticket 7 — Evidence & freeze

**Files:** `07-evidence/`, `08-retrospective.md`, `decisions.md`, `docs/PRODUCT_BLUEPRINT.md` §4 (fold), `docs/UI_Standards.md`
**Dependencies:** Tickets 1–6

### Work
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`
- [ ] Verdict output for every claim saved under `07-evidence/`
- [ ] Schema deltas folded into `docs/PRODUCT_BLUEPRINT.md` §4; status-map extension into `docs/UI_Standards.md`; decisions recorded
- [ ] `08-retrospective.md`: what should change before the documents phase

### Acceptance criteria
- [ ] All 14 brief verdicts green with evidence linked
- [ ] CI green on the feature branch; merged per the review protocol
