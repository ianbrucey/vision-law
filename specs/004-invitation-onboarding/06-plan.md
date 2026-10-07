# 004 — Invitation onboarding UI — Implementation plan

**Status:** DRAFT (pending mockup approval — do not execute before Ian approves `05-ui-mockup.html`)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Backend-out tickets. The invitation domain logic already exists (spec 001); these tickets add the response layer and the views. Parallel workers get disjoint file ownership.

---

## Ticket 1 — Response layer: Blade for web, JSON preserved

**Files:** `app/Http/Controllers/Admin/InvitationController.php`, `app/Http/Controllers/InvitationController.php`, `routes/web.php` (route names only — paths unchanged)
**Inputs:** `03-contract.md` §Routes, 004-D01
**Dependencies:** none (baseline ticket)
**Forbidden edits:** `app/Services/InvitationService.php`, `app/Models/Invitation.php`, `app/Actions/Fortify/CreateNewUser.php`, migrations, `database/seeders/PermissionMatrixSeeder.php`

### Work
- [ ] Both controllers: return Blade views for web requests, keep the existing JSON shapes when `$request->wantsJson()`.
- [ ] Admin `store`: on success, redirect to `admin.invitations.index` with the one-time accept link flashed to the session (mechanism per 004-D01 — proposed in T-01, reviewed before merge; token never logged, never persisted).
- [ ] Public `show`: valid token → accept view data (org name, role, matter name if scoped, expiry); invalid → identical 404 Blade in all four states.
- [ ] Public `accept`: web success → redirect `/` with welcome toast; failure → existing generic `invitation_invalid` as a page banner.

### Acceptance criteria
- [ ] Existing JSON contract tests for both controllers still green (no shape changes for API clients).
- [ ] `test_web_requests_receive_blade_views` — GETs with `Accept: text/html` return views; with `Accept: application/json` return the old JSON.
- [ ] Architecture suite + Pint + PHPStan green.

---

## Ticket 2 — Admin invitations UI

**Files:** `resources/views/admin/invitations/index.blade.php` (+ revoke modal partial)
**Inputs:** `05-ui.md` §Admin invitations page, approved `05-ui-mockup.html` `#s-admin`, `04-fixtures.json` cases INV-01–INV-06
**Dependencies:** Ticket 1
**Forbidden edits:** same protected list as T-01

### Work
- [ ] Page header, mail-not-configured banner, one-time accept-link banner with copy button.
- [ ] Create form (`x-ui.field` email, `x-ui.select` role from `InvitationService::validRoles()`, `x-ui.select` matter scope from the org's matters, submit `x-ui.button`); inline validation errors.
- [ ] Invitations table (`x-ui.table` + `x-ui.status` + `x-ui.chip`); revoke action only on pending rows; revoke `x-ui.modal` with danger + consequence line; `x-ui.empty` state; `x-ui.toast` on create/revoke.

### Acceptance criteria
- [ ] C-01 — `test_admin_invitations_index_lists_only_org_invitations` (INV-06 absent).
- [ ] C-02 — `test_admin_creates_invitation_and_receives_accept_link`.
- [ ] C-03 — `test_admin_revokes_pending_invitation_from_ui`.
- [ ] Architecture doors green on the new views; Pint clean.

---

## Ticket 3 — Invitation accept UI

**Files:** `resources/views/invitations/show.blade.php`
**Inputs:** `05-ui.md` §Accept page, approved `05-ui-mockup.html` `#s-accept-ok` / `#s-accept-bad`
**Dependencies:** Ticket 1
**Forbidden edits:** same protected list

### Work
- [ ] Valid-token view: org name, role chip, matter-scope line, expiry note; "Create your account" form (name + password via `x-ui.field`, email locked, hidden `invitation_token`) posting to existing `register.store`; "Already have an account? Sign in" linking to `login` with return to the token URL.
- [ ] Invalid-token view: identical generic page for all four failure states.

### Acceptance criteria
- [ ] C-05 — `test_invitee_accepts_invitation_through_ui_new_user` (ends with `accepted_at` set, role assigned, matter grant created when scoped) and `test_existing_user_accepts_invitation_through_ui`.
- [ ] C-06 — `test_invalid_token_shows_safe_not_found_page` (four token states → identical page).
- [ ] Architecture doors green; Pint clean.

---

## Ticket 4 — Authorization, leak sentinels, doors

**Files:** `tests/Feature/InvitationOnboardingTest.php` (sentinel/authz cases), `tests/Architecture/VisionUiTest.php` (only if new paths need registering)
**Inputs:** `00-brief.md` §Security classification, `03-contract.md` §Role-visible data
**Dependencies:** Tickets 2–3
**Forbidden edits:** same protected list

### Work
- [ ] Authz matrix: attorney/viewer/anonymous × the three admin routes → existing denial semantics.
- [ ] Leak sentinels: `token_hash` absent from all rendered HTML; plaintext token present only inside the flashed accept link; invitee emails never render outside their org.
- [ ] Confirm the new views trip no architecture door (buttons, form controls, page titles, modals, light-only).

### Acceptance criteria
- [ ] C-04 — `test_non_admin_cannot_access_invitation_admin_ui`.
- [ ] C-07 — `test_token_hash_never_leaks_in_ui`.
- [ ] C-08 — doors green.
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`.

---

## Ticket 5 — Evidence & freeze

**Files:** `07-evidence/`, `08-retrospective.md`, `decisions.md`
**Dependencies:** Tickets 1–4

### Work
- [ ] Full suite green; verdict output for every claim in `00-brief.md` saved under `07-evidence/`.
- [ ] No schema delta to fold; no new UI primitives beyond the 002 inventory (confirm in UI_Standards — no edit expected).
- [ ] Decisions made during execution recorded in `decisions.md`.
- [ ] `08-retrospective.md`: what should change before the next feature (matter model).

### Acceptance criteria
- [ ] All brief verdicts green with evidence linked.
- [ ] CI green on the feature branch; merged per the review protocol (poller auto-deploys).

---

<RULES:>
- A ticket is complete only when its own verdict AND the standing architecture/style gates are green.
- A failing test is fixed at the cause — assertions are never weakened; if a governing decision changed, update the decision first, then the test.
- Stop on contract drift: update and re-approve the artifact before continuing.
- No opportunistic cleanup.
