# 009 — Archaeology

**Spec:** 009 — Legal calendaring, tasks, reminders, and deadline templates
**Branch:** `feat/009-calendaring` (from main `8b34ac3`)
**Date:** 2026-10-19

## Baseline (recorded before any 009 change)

| Gate | Result |
|---|---|
| Pint (`vendor/bin/pint --test`, sim clone) | PASS — 280 files |
| PHPStan level 7 | OK — no errors, no baseline file exists |
| Full suite | 252 passed / 0 failures / 0 skipped (CI simulator at `26b0416`; `8b34ac3` is a docs-only merge on top — brief approval only) |
| Live tree | `/opt/vision-law` stays on `main` for the whole build; work happens in the sim clone and lands by merge |

## REUSE / EXTEND / NEW / CONFLICT

| Surface | Verdict | Notes |
|---|---|---|
| `App\Services\AccessControl` (authorize / effectiveMatterRole / ROLE_RANK / ACTION_MIN_ROLE) | **EXTEND** | Single source of truth for matter authorization. Org default: org_admin = implicit `matter_owner` on **all** org matters; everyone else default-deny without a grant; outside_counsel ignores the org default; expired grants deny; cross-org = no access. Calendaring adds **no** new permission system — every item read/write goes through `authorize($user, $action, $matter)`. Extension is only for O-03 (below). |
| `App\Policies\MatterPolicy` | REUSE | Delegates to AccessControl; calendaring controllers follow the same pattern. |
| `RequireMatterAccess` middleware (`matter.access:<action>`) | REUSE | Resolves the matter before any data access, 404/403 leak rules, audits every denial as `matter.access.denied` against the actor's org. All matter-nested calendaring routes carry it. |
| `Matter::scopeAccessibleBy` | **EXTEND** (with AccessControl) | Canonical list scoping (org_admin → all org matters; others → valid direct/team grants, applied before pagination). Calendaring list/agenda queries build on it. Must mirror the O-03 restriction exactly or C-01 leaks through lists while detail pages stay safe. |
| `AuditLogger` + `audit_events` (hash-chained, DB trigger append-only, model guards) | REUSE | Sole writer. **Constraint:** payload keys containing `password`/`secret`/`token`/`hash`/`recovery` are rejected at any depth — calendaring audit payloads must use neutral key names (feed *id*, share *id*; never a `*_token` key, never the token value). Event names follow the existing dot style (`matter.access.denied`, `document.…`). |
| `AuditEvent` read model (010 home activity, audit viewer) | REUSE | Calendaring events appear in the home activity feed automatically once written with matter scope. |
| Spec 006 matter model (`Matter`, `MatterGrant`, `Team`, lifecycle, parties, comments) | REUSE | Items hang off matters; assignees/watchers are users/teams/roles resolved against grants at send time. `matters.template_id`/`template_version` already exist (006 stub) — 009 instantiation stamps them. |
| Spec 007 `DocumentTemplate` + `templates.index` route name | **CONFLICT (naming)** | 007 owns document templates and the `templates.*` route names + `/templates` chrome entry. 009's deadline/matter templates are a different concept: distinct models (`DeadlineTemplate`), distinct routes (`/calendar/templates`, names `calendar.templates.*`). No 007 surface is touched. |
| Spec 010 home (`HomeController`, `home.blade.php`) | **EXTEND** | 010-D08 reserves the integration point verbatim: "009's deadlines module joins `home.blade.php` below activity at 009 Plan time." HomeController gains a deadlines/agenda data assembly over the same 006 scope; no 010 test changes. |
| Laravel database queue (`jobs`/`failed_jobs` tables exist; prod `QUEUE_CONNECTION=database`; tests `sync`) | REUSE | Reminder delivery jobs ride the standard queue; C-12 verdicts must behave identically under sync (test) and database (prod) drivers. |
| Scheduler (`routes/console.php` already schedules 3 commands) | EXTEND | Add `reminders:dispatch` (every minute) and `reminders:escalate` (every five minutes). **Production gap:** nothing currently runs `schedule:run`/`schedule:work` or `queue:work` on the server — O-07 runtime work (T-19) closes it. |
| Mail (`app/Mail/*` Mailable pattern; prod `MAIL_MAILER=log`) | EXTEND | Email channel coded as a Mailable behind config; prod mail transport is effectively unconfigured (log mailer), so the channel ships disabled by default and the ledger records unconfigured attempts loudly (O-07). Tests use `Mail::fake` / array mailer — no live provider anywhere. |
| In-app notifications | NEW (framework-native) | No `notifications` table or Notification classes exist yet. 009 adds the Laravel database-notifications table + a `ReminderNotification`; the delivery ledger stays the system of record (the notification row is the in-app channel's artifact). |
| `<x-ui.*>` components + `layouts/app` chrome (002/010) | REUSE | banner, button, card, chip, empty, field, kv, modal, page-header, pagination, select, stat, status, sub-nav, table, textarea, toast. No new component library work; status cues use `x-ui.status` + text labels (non-color cues, C-20). |
| `Tests\Helpers\FixtureLoader` | EXTEND | Canonical loader pattern (001/006/007/010 graphs). 009 adds `loadCalendaringFixtures()` reading `specs/009-calendaring/04-fixtures.json` — the single canonical definition (protocol: one definition only). |
| Hermetic doubles (`Tests\TestCase` bindings) + mobile gate technique (007/010) | REUSE | C-20 mirrors `AppHomeMobileTest`: standalone-ized HTML, 390px headless Chromium via `CHROMIUM_BIN`, sys-temp staging, graceful skip when no binary can read staged files. |
| Older `~/workspace/georgia-rfi/specs/02-legal-calendaring.md` (CAL-01–22) | **CONFLICT (numbering)** | Superseded for 009 purposes per the brief: the blueprint v1.0 numbering (CAL/TASK/REM/TMP/SYNC/SEC/AUD/NFR/QA) is authoritative. The old file is not consulted. |
| `matters` table | **EXTEND** | No `restricted` flag exists — see O-03 finding below. |

## O-03 finding (the one real gap)

**006's authorization matrix has no ethical wall.** `AccessControl::effectiveMatterRole()` grants org_admin implicit `matter_owner` on every matter in the org, unconditionally, and `scopeAccessibleBy()` mirrors that. There is no restricted/confidential matter flag anywhere in the schema, the matrix doc, or the 006 fixtures. The brief's fixture list and security classification nevertheless require a restricted-matter case ("org admin — no automatic matter-content access under ethical-wall mode"), and C-01's verdicts are written to hold either way while "the data model differs."

Council resolution (009-D03): add an opt-in `matters.restricted` boolean, default `false`. When `true`, the org-default branch is skipped in **both** `AccessControl` and `scopeAccessibleBy` (grants still apply normally). Default-off means every existing 006/007/010 behavior and test is bit-identical; the wall exists only where a matter manager explicitly turns it on (audited). This is recorded as an EXTEND decision, not smuggled in as a discovered behavior.

## Other findings that shape the plan

- **Optimistic concurrency precedent exists** (`MatterTransitionConcurrencyTest`) — C-03's conflict behavior follows the same shape (explicit version check, conflict surfaced with intervening values, no silent overwrite).
- **Audit payload blocklist** (above) also forbids a `hash` key — template-version checksums are stored on the template row, never inside audit payloads.
- **Feed/guest tokens** follow the 004/007 pattern: opaque plaintext shown once, SHA-256 hash stored, revocable, expiry-aware (`DocumentShare`, `Invitation` precedents).
- **Time handling:** app timezone is UTC storage via Eloquent datetimes; 009 stores timed items as UTC instant + originating IANA zone column, and legal due dates as `date` + jurisdiction zone (C-18), mirroring how `closed_at`/grant `expires_at` already behave.
- **Deploy/runtime:** prod runs `visionlaw-web` (artisan serve) + `visionlaw-webhook` (poller) only. No worker, no scheduler process. T-19 adds both units; deploy.sh runs `migrate --force` only, so new tables arrive via the normal merge→CI→poller path with no seeder dependency (holiday packs + demo template are seeded by an idempotent artisan command run once post-deploy, and by fixtures in tests).

## Files-to-touch (summary; per-ticket detail in 03-plan.md)

| Area | Action |
|---|---|
| `database/migrations/*` (009 set) | NEW — restricted flag; calendar_items (+attendees); task_dependencies; checklist_items; reminder_policies; reminder_deliveries; user reminder preferences; deadline_templates (+versions); holiday_calendars (+holidays); template_instantiations; guest_shares; calendar_feeds; notifications table |
| `app/Models/*` (009 set) | NEW — CalendarItem, ItemAttendee, TaskDependency, ChecklistItem, ReminderPolicy, ReminderDelivery, ReminderPreference, DeadlineTemplate, DeadlineTemplateVersion, HolidayCalendar, Holiday, TemplateInstantiation, GuestShare, CalendarFeed |
| `app/Services/AccessControl.php`, `app/Models/Matter.php` | EXTEND — O-03 restricted branch in both places, nothing else |
| `app/Services/Calendaring/*` | NEW — ItemService, AgendaQuery, TaskService, RecurrenceService, ReminderPlanner, ReminderDispatcher, DeliveryService, TemplateService, DeadlineEngine, RecalculationService, IcsService, GuestShareService |
| `app/Jobs/SendReminderDelivery.php`, `app/Notifications/ReminderNotification.php`, `app/Mail/ReminderMail.php` | NEW |
| `app/Console/Commands/*` | NEW — DispatchReminders, EscalateReminders, SeedCalendaringData |
| `app/Http/Controllers/Calendaring/*`, `app/Http/Controllers/HomeController.php` | NEW + EXTEND (010-D08 module) |
| `routes/web.php`, `routes/console.php`, `config/calendaring.php` | EXTEND/NEW |
| `resources/views/calendaring/*`, `resources/views/home.blade.php`, `resources/views/matters/show.blade.php` (calendar tab link) | NEW + EXTEND |
| `database/data/holiday-calendars/*.json`, `database/data/rule-packs/*.json` | NEW — versioned DEMONSTRATION data packs (O-02/O-04) |
| `tests/Feature/Calendaring*.php`, `tests/Unit/DeadlineEngineTest.php` (engine goldens live in Feature per claim naming), `tests/Helpers/FixtureLoader.php` | NEW + EXTEND |
| `specs/009-calendaring/*` | NEW — this pipeline's artifacts |

## Protected files (must not edit)

- `tests/Feature/TwoFactorScreensTest.php` and all spec 005 tests; the 2FA exemption code/config behavior (`config/fortify.php` exemption key, `FortifyServiceProvider` pipeline).
- All spec 010 tests (`AppHomeTest`, `AppHomeMobileTest`) — the home changes must keep them green unmodified.
- Spec 006/007/008 test suites (behavior-preserving extensions only; assertions untouched).
- `specs/009-calendaring/00-brief.md` (approved text).
- The live `vision_law` database: no migrate/seed/fresh against it, ever (commandment 8). Tests run against `vision_law_test` only.
