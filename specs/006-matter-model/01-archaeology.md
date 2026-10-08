# 006 — Matter model and lifecycle — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Brief:** `00-brief.md`

---

## Findings

| Thing (code, migration, test, doc, config) | Location | Verdict | Constraint / note |
|---|---|---|---|
| `matters` table (`id`, `org_id`, `matter_number` unique per org, `title`, `status` default `'open'`) | `database/migrations/2026_10_07_020007_create_matters_table.php` | EXTEND | 001-D03 stub. Add `lifecycle_state`, `matter_type`, `description`, `client_name`, `deleted_at`, `closed_at`, `template_id/version`; DROP `status` per 006-D02 (only `Matter::$fillable` reads it; no other readers found) |
| `Matter` model (HasUuids, `organization()`, `grants()`) | `app/Models/Matter.php` | EXTEND | Add fillable + relations: `parties()`, `comments()`, `links()`, `documentLogs()`, `assignments()` (via grants), `commentReads()` |
| `matter_grants` table (matter/user/team, role, expires_at, partial uniques, exactly-one-subject check) | `database/migrations/2026_10_07_020008_create_matter_grants_table.php` | REUSE | Assignment (MAT-07) rides on this table — no new grants table. Roles owner/editor/viewer/outside_counsel already in use |
| `MatterGrant` model | `app/Models/MatterGrant.php` | REUSE | |
| `MatterGrantController@store/destroy` (`matters.grants.*` routes) | `app/Http/Controllers/MatterGrantController.php` | EXTEND | Keep JSON behavior; add last-owner protection via new `MatterService` (controller calls service, doesn't duplicate logic) |
| `MatterController@show` (`matters.show`, `matter.access:view`) | `app/Http/Controllers/MatterController.php` | EXTEND | Becomes the full detail page; add index/create/store/edit/update/destroy/restore/transition/close/summary actions |
| `RequireMatterAccess` middleware (`matter.access:<action>`, 404-not-403, audit on deny) | `app/Http/Middleware/RequireMatterAccess.php` | EXTEND | New action levels: `comment`, `edit`, `manage` (existing: `view`, `grant`). `grant` ≈ `manage` for assignments — contract reconciles |
| `AccessControl::authorize($user, $action, $matter)` | `app/Services/AccessControl.php` | EXTEND | Teach it the new actions; org_admin → allow; grant role ≥ required → allow; expired grants ignored |
| `AuditLogger::log($event, $actor, $payload, $matter, $explicitOrgId)` | `app/Services/AuditLogger.php` | REUSE | Pass `$matter` on every 006 mutation; payloads carry from/to, note, party/comment/link ids |
| `audit_events.matter_id` (nullable FK) + hash-chain trigger | `2026_10_07_020009` + `021000` | REUSE | Activity feed (MAT-06) is a read model over this table — no new table (006-D04) |
| `InvitationService::grantMatterScope()` | `app/Services/InvitationService.php` | REUSE | Matter-scoped invitation acceptance already creates grants |
| `x-ui.status` MAP `['matter']` (open/active/on_hold/closed/archived placeholders) | `app/View/Components/Ui/Status.php` | EXTEND | Replace with the 8 lifecycle states + tones; fail-closed behavior stays |
| `x-ui.table/page-header/chip/modal/field/select/button/textarea/banner/empty-state` | `resources/views/components/ui/*` | REUSE | All 006 views compose these; no new primitives expected |
| `FixtureLoader` (loads `specs/001/.../04-fixtures.json`) | `tests/Helpers/FixtureLoader.php` | EXTEND | Add `loadMatterFixtures()` reading this spec's `04-fixtures.json` |
| `MatterGrantsTest` (authz proving ground) | `tests/Feature/MatterGrantsTest.php` | REUSE | Keep green; 006's matrix test extends the pattern, doesn't duplicate it |
| `invitations.matter_id` FK | `2026_10_07_020007` | REUSE | 004's matter-scope select already reads matter titles |
| Seeded demo matters (`MAT-2026-001` Sterling v. Apex Construction, `MAT-2026-002`, `MAT-2026-101`) | `visionlaw-pg` (dev DB) | REUSE | Backfill maps `status='open'` → `lifecycle_state='INTAKE'` |
| `User` model `email_verified_at` not in `$fillable` | `app/Models/User.php` | CONFLICT | Unrelated to 006, but 006's tests create verified users — use `forceFill` in fixtures (already the established workaround); do NOT "fix" fillable here |
| `PermissionMatrixSeeder::seedFor($org)` | `database/seeders/PermissionMatrixSeeder.php` | REUSE | New orgs in fixtures get roles via this |

## Architectural doors this feature must use

- All matter access goes through `RequireMatterAccess` / `AccessControl::authorize` **before** any data access — no controller reads a matter first.
- All mutations pass through `MatterService` — controllers never write matter tables directly.
- Audit events only through `AuditLogger`, always with `$matter` set on matter mutations.
- All views compose `<x-ui.*>` — the architecture doors from 002 stay green.
- 404-not-403 on denial where existence must not leak (unassigned / other-org / anonymous).

## Files to touch

| File | Action | Owner | Reason |
|---|---|---|---|
| `database/migrations/2026_10_07_1*_*.php` (new, ordered) | create | orchestrator | Schema delta §Tables (matters alter; 5 new tables) |
| `app/Models/Matter.php` | edit | T-01 | Fillable + relations |
| `app/Models/MatterParty.php`, `MatterComment.php`, `MatterLink.php`, `MatterDocumentLog.php`, `MatterCommentRead.php` | create | T-01 | New models |
| `app/Services/MatterService.php` | create | T-01/T-02 | Number generation, transitions (serialized), closure, assignment w/ last-owner guard, search, summary |
| `app/Services/AccessControl.php` | edit | T-05 | New action levels |
| `app/Http/Middleware/RequireMatterAccess.php` | edit | T-05 | New action levels |
| `app/Http/Controllers/MatterController.php` | edit | T-02/T-06 | CRUD + transitions + close/restore/summary |
| `app/Http/Controllers/{Party,Comment,Link,DocumentLog,Assignment}Controller.php` | create | T-03/T-04 | Per-resource endpoints |
| `app/Http/Controllers/MatterGrantController.php` | edit | T-04 | Delegate to MatterService (last-owner protection) |
| `routes/web.php` | edit | orchestrator | 006 routes (shared file) |
| `app/View/Components/Ui/Status.php` | edit | T-06 | Lifecycle states in the matter map |
| `resources/views/matters/**` | create | T-06 | index, show (+tabs), form, partials |
| `tests/Helpers/FixtureLoader.php` | edit | T-01 | `loadMatterFixtures()` |
| `tests/Feature/Matter*Test.php` | create | T-02…T-06 | Verdict tests per ticket |
| `docs/UI_Standards.md` | edit | freeze | Status-map extension folded in |

## Protected files

- `database/migrations/2026_10_07_0200*.php` (001's migrations) — never edited; new migrations only.
- `app/Http/Controllers/Auth/*`, `app/Actions/Fortify/*` — auth surface owned by 001/005.
- `app/Services/AuditLogger.php`, `app/Services/InvitationService.php` — reuse as-is.
- `app/Services/LoginAttemptService.php`, `app/Listeners/EnforceSessionPolicies.php` — auth policy, out of scope.
- `resources/views/components/ui/*` — extend via props/map only; no primitive changes in this feature.

## Baseline

Last CI green at `eb403ae` (2026-10-07): 152 tests / 1755 assertions passed; `vendor/bin/pint --test` clean (123 files); `vendor/bin/phpstan analyse` no errors (level 7); `npm run build` success. (Dev dependencies are not installed on the server checkout — the suite runs in CI; record the CI result, not a local run.)
