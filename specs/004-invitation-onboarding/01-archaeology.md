# 004 — Invitation onboarding UI — Repository archaeology

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Brief:** `00-brief.md` (DRAFT — archaeology written against the draft brief; re-verify on approval)

> Read the code — done 2026-10-07 against `/opt/vision-law` at main. No guessing.

---

## Findings

| Thing | Location | Verdict | Constraint / note |
|---|---|---|---|
| `InvitationService::invite/accept/revoke/findValid/grantMatterScope` | `app/Services/InvitationService.php` | REUSE | Complete lifecycle; token hashed at rest; audit via `AuditLogger`. Do not modify. |
| `Admin\InvitationController` (index/store/destroy) | `app/Http/Controllers/Admin/InvitationController.php` | EXTEND | JSON-only today ("no Blade per 001-D06"). Add Blade responses for web requests; keep JSON for API clients. |
| `InvitationController` (show/accept) | `app/Http/Controllers/InvitationController.php` | EXTEND | `show` (guest, token landing) gets a Blade accept page; `accept` (auth) keeps JSON + adds web redirect. No-enumeration semantics must be preserved exactly. |
| `Invitation` model | `app/Models/Invitation.php` | REUSE | Append-mostly (no `updated_at`); relations to org/inviter/matter. |
| `CreateNewUser` (invitation_token path) | `app/Actions/Fortify/CreateNewUser.php` | REUSE | New-user accept path already works: register with token → org+role+matter grant atomically. The accept page's "create account" form posts here. |
| `invitations` table | migration `2026_10_07_020004` | REUSE | Exists. No schema delta. |
| `matters` table | migration `2026_10_07_020007` | REUSE | Exists; the create form's optional matter-scope select reads org matters from it. No matter-detail UI is built here. |
| `RequireOrgAdmin` middleware + `admin` prefix group | `routes/web.php` | REUSE | `admin/invitations*` already gated to org admins, org-scoped queries in the controller. |
| `<x-ui.table>`, `<x-ui.select>`, `<x-ui.field>`, `<x-ui.button>`, `<x-ui.modal>`, `<x-ui.chip>`, `<x-ui.status>`, `<x-ui.banner>`, `<x-ui.empty>`, `<x-ui.page-header>`, `<x-ui.card>` | `resources/views/components/ui/` | REUSE | The full inventory from spec 002. The create form uses field/select/button; the table uses table+status+chip; revoke uses modal (danger + consequence line). |
| App shell layout | `resources/views/layouts/` (from spec 002/003) | REUSE | Admin page lives inside the authenticated app shell; the accept page uses the public/guest layout (like login). |
| `InvitationMail` mailable | `app/Mail/InvitationMail.php` | REUSE | Already queued by the service. Mail *transport* is not configured on the server — hence 004-D03 (copy-paste link). |
| Admin invitation Blade views | — | NEW | None exist. `admin/invitations/index.blade.php` (+ revoke modal partial). |
| Invitation accept Blade view | — | NEW | None exists. `invitations/show.blade.php` (valid + invalid states). |
| `PermissionMatrixSeeder::MATRIX` role list | `database/seeders/PermissionMatrixSeeder.php` | REUSE | Five fixed roles (`org_admin, attorney, paralegal, outside_counsel, viewer`); the role select options come from `InvitationService::validRoles()`. |

**Conflicts:** none found. The JSON-only controllers are an intentional 001-D06 deferral, not a contradiction — extending them with Blade responses is the planned completion.

## Architectural doors this feature must use

- Views use `<x-ui.*>` components only (doors 1–7, enforced by `tests/Architecture/VisionUiTest.php`); light-only.
- All invitation mutations pass through `InvitationService` — controllers never touch the `invitations` table directly (the existing controllers already obey this; the Blade extension must not add direct writes).
- Audit events only through `AuditLogger` (already inside the service; no new events).
- Token plaintext is a bearer secret: rendered exactly once (the accept link for the creating admin), never logged, never in `token_hash` form anywhere near a view.

## Files to touch

| File | Action | Owner | Reason |
|---|---|---|---|
| `app/Http/Controllers/Admin/InvitationController.php` | edit | T-01 | Add Blade responses (`wantsJson()` keeps JSON); flash the one-time accept link on create |
| `app/Http/Controllers/InvitationController.php` | edit | T-01 | `show` returns the accept Blade page (404 Blade on invalid); `accept` redirects for web |
| `routes/web.php` | edit | T-01 | Same paths; name the new view routes (already named); no new routes needed |
| `resources/views/admin/invitations/index.blade.php` | create | T-02 | Admin table + create form + revoke modal |
| `resources/views/invitations/show.blade.php` | create | T-03 | Accept page: valid state (org/role/matter + create-account/sign-in paths) and invalid state |
| `tests/Feature/InvitationOnboardingTest.php` | create | T-02–T-04 | Named verdict tests C-01–C-07 |
| `tests/Architecture/VisionUiTest.php` | edit | T-04 | New views fall under the existing doors automatically; add explicit door assertions if the suite needs new paths registered |

## Protected files

- `app/Services/InvitationService.php` — REUSE, do not modify (business rules frozen by 001).
- `app/Models/Invitation.php` — REUSE.
- `app/Actions/Fortify/CreateNewUser.php` — REUSE.
- `database/seeders/PermissionMatrixSeeder.php` — role list is canonical.
- `app/Http/Controllers/Admin/*` (other controllers) — out of scope.
- Migrations — none added, none edited.

## Baseline (2026-10-07, post spec-003 merge `a4ded6e`)

- Tests: `php artisan test` — 124 passed, 1132 assertions (green)
- Architecture tests: green (part of the suite)
- Pint: `vendor/bin/pint --test` — clean
- PHPStan: level 7 — no errors
- Asset build: `npm run build` — success
- Framework: laravel/framework 13.x (per composer.lock at scaffold; re-verify at execution)
