# 004 — Invitation onboarding UI — Strategic brief

**Status:** APPROVED — Ian Bruce, 2026-10-07
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07

---

## Goal

An organization admin can invite a person by email and revoke the invitation, and the invitee can accept through a link — entirely through the UI, so the invite-only platform posture Ian decided on 2026-10-07 has a working human door.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Org admin sees the org's invitations in a table (email, role, matter scope, status, expiry, created) | `test_admin_invitations_index_lists_only_org_invitations` — GET `admin/invitations` as org_admin renders 200 with every org invitation; a second org's invitations are absent | — (UX necessity: the invite-only posture has no human door today) |
| C-02 | Org admin creates an invitation via the form; the accept link is displayed once for copy-paste | `test_admin_creates_invitation_and_receives_accept_link` — POST `admin/invitations` with valid email+role creates the row and the response contains the accept URL containing the plaintext token exactly once | — (same) |
| C-03 | Org admin revokes a pending invitation through the UI with the danger + consequence-line pattern | `test_admin_revokes_pending_invitation_from_ui` — DELETE sets `revoked_at`, row shows "Revoked" | — (same) |
| C-04 | Non-admins cannot reach the admin invitation UI | `test_non_admin_cannot_access_invitation_admin_ui` — attorney/viewer/anonymous get the existing denial semantics on all three admin routes | — (security hardening of the new surface) |
| C-05 | Invitee with a valid token sees org name + granted role (+ matter name if matter-scoped) and can accept: new users create an account bound to the token, existing users sign in then accept | `test_invitee_accepts_invitation_through_ui_new_user` and `test_existing_user_accepts_invitation_through_ui` — both end with `accepted_at` set, role assigned, matter grant created when scoped | — (prerequisite for outside-counsel sharing flows) |
| C-06 | Invalid, expired, revoked, or already-accepted tokens show the safe "not found" page with no distinguishing detail | `test_invalid_token_shows_safe_not_found_page` — four token states → identical generic page, 404 | — (no-enumeration law, already backend behavior) |
| C-07 | The token hash never appears in any rendered output; the plaintext token appears only inside the single accept link shown to the creating admin | `test_token_hash_never_leaks_in_ui` — leak-sentinel scan of admin index HTML, accept page HTML, and logs | — (credential-hygiene law) |
| C-08 | All new views use `<x-ui.*>` components only; the enforced doors hold | `VisionUiTest` doors green on the new views (buttons, form controls, page titles, modals, light-only) | — (002-D02 decided UI law) |

## Blueprint anchors

- [D] 001-D09 self-registration allowed — the invitation-token registration path (`CreateNewUser` with `invitation_token`) is the new-user accept mechanism; this spec only gives it a face.
- [D] Append-only audit — `invitation.created` / `invitation.revoked` / `invitation.accepted` / `invitation.accept.denied` already emitted by `InvitationService`; this spec adds no new events and must not bypass the service.
- [D] 002-D02 `docs/UI_Standards.md` decided — all views use the `<x-ui.*>` inventory; light-only; the 003-D01 dark-hero exception does NOT extend here (admin and accept pages are app surfaces, light only).
- [D] 001-D13 system org can never be an invitation target — enforced in `InvitationService::invite`; the UI adds nothing.
- [P] None load-bearing.
- [O] None load-bearing. Mail delivery is deferred (see 004-D03), not open.

## Security classification

- **Actors:** org_admin (invitation management), invitee/guest (token link), existing signed-in user (accept path), anonymous (denied everywhere except the token landing page).
- **Data classes touched:** internal (invitation metadata: email, role, status, expiry), confidential (invitee email addresses in aggregate — the admin table).
- **Allow events (audit):** `invitation.created`, `invitation.revoked`, `invitation.accepted`, `matter.grant.created` — all already emitted by the service layer.
- **Denial events (audit):** `invitation.accept.denied` (already emitted); admin-route denials follow the existing `RequireOrgAdmin` semantics.
- **Leak sentinels:** `token_hash` values must never appear in HTML/JSON/logs; the plaintext token must appear ONLY inside the accept URL shown once to the creating admin (and in the mailed URL when mail is configured — deferred). Invitee emails must never render for users outside the invitation's org.

## Evidence and fixtures

Canonical fixtures in `04-fixtures.json`: pending invitation (matter-scoped), pending invitation (org-wide), accepted invitation, revoked invitation, expired invitation, cross-org invitation (adversarial — must never appear in org A's admin table), malformed token string. All synthetic.

## Pre-mortem

- **Likely failure:** mail is not configured on the server, so the "invitation email" path silently goes nowhere — mitigated by 004-D03 (copy-paste accept link displayed at creation; mail config explicitly deferred, not silently assumed).
- **Assumption:** the admin create form's matter-scope select can list the org's matters from the existing `matters` table. True today (table exists), but the matter *model* spec is deferred — the select shows matter names only, no matter-detail links, so this spec makes no promises about matter screens.
- **Unknown:** whether Ian wants a branded invitation email template later — out of scope; the `InvitationMail` mailable already exists for when delivery is configured.
- **Mitigation:** the accept link is a bearer secret — single-use, 7-day TTL, SHA-256 hashed at rest, shown once. The invalid-token page is identical across all four failure states (backend already guarantees this; the Blade page must not add distinguishing copy).

## Non-goals

- Email delivery configuration or branded invitation email design (deferred; `InvitationMail` already exists).
- A full admin dashboard or user directory UI — this spec is the invitations page only.
- Invitation "resend" — revoke + recreate covers it; no new endpoint.
- SSO, org creation UI, role management UI.
- Changing any invitation business rule — the service is reused as-is.

## Approval gate

- [ ] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [x] Product owner approved — **approver:** Ian Bruce · **date:** 2026-10-07
