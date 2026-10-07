# 004 — Invitation onboarding UI — Contract

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Schema:** `02-schema-delta.md` (no change)

> Routes, validation, errors, authorization, and audit are decided here — not during implementation. Views may request only what this contract exposes. All invitation mutations go through `InvitationService`; controllers add presentation only.

---

## Domain service methods

No new service methods. Reused as-is:

| Method | Inputs | Outputs | Errors |
|---|---|---|---|
| `InvitationService::invite(org, email, role, ?matter, invitedBy)` | org, normalized email, one of `validRoles()`, optional same-org matter, admin user | `Invitation` (token NOT returned — see 004-D01) | `ValidationException`: bad email / bad role / cross-org matter / system-org target |
| `InvitationService::accept(token, user)` | plaintext token, signed-in user | `User` (fresh) | `InvitationInvalidException` — generic, no enumeration; audited as `invitation.accept.denied` |
| `InvitationService::revoke(invitation, revokedBy)` | invitation, admin | void (idempotent) | `ValidationException` if already accepted |
| `InvitationService::findValid(token)` | plaintext token | `?Invitation` (usable only) | none — null on any failure |

**004-D01 consequence:** `invite()` does not return the plaintext token, so the controller must capture it. The service generates the token internally; the controller needs the accept URL for the one-time display. Implementation detail (not a contract change): the controller generates no token itself — the approved approach is for `invite()` to remain untouched and the controller to obtain the token via a service accessor (e.g. the service returns the token alongside the invitation in a small DTO, or the controller uses the existing flow and the token is exposed through a narrow, reviewed addition). The builder proposes the exact mechanism in T-01; the token must never be logged or stored beyond the hashed column.

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `admin/invitations` | `Admin\InvitationController@index` | auth + `RequireOrgAdmin` | org admin of the org; query scoped to `org_id` (existing) |
| POST | `admin/invitations` | `Admin\InvitationController@store` | auth + `RequireOrgAdmin` | same; `matter_id` must belong to the admin's org or 404 |
| DELETE | `admin/invitations/{invitation}` | `Admin\InvitationController@destroy` | auth + `RequireOrgAdmin` | invitation must belong to the admin's org or 404; accepted invitations cannot be revoked |
| GET | `/invitations/{token}` | `InvitationController@show` | guest | none — token is the credential; invalid/expired/revoked/accepted → identical 404 page |
| POST | `/invitations/{token}/accept` | `InvitationController@accept` | auth | signed-in user's email must match invitee email and org must match, else generic `invitation_invalid` (existing) |

Response negotiation (004-D01): web requests (`Accept: text/html`, the default from browsers) receive Blade views / redirects; API clients (`Accept: application/json` / `wantsJson()`) receive the existing JSON shapes unchanged. No new routes.

## Requests & validation

### POST `admin/invitations`

| Field | Type | Required | Validation | Error on invalid (web) |
|---|---|---|---|---|
| `email` | string | yes | email, max:255 | 422 → back with inline `x-ui.field` error |
| `role` | string | yes | `in:` the five fixed roles | 422 → back with inline error |
| `matter_id` | uuid | no | nullable, uuid, must belong to admin's org | 404 (no existence leak) |

On success (web): 201-equivalent → redirect to `admin.invitations.index` with the **one-time accept link** flashed to the session and rendered in a copy-paste banner (C-02). The link is shown once and never persisted.

### DELETE `admin/invitations/{invitation}` (web)

Revoke → redirect back with a toast confirming revocation (C-03). Revoking an already-accepted invitation → 422 with the existing message.

### GET `/invitations/{token}` (web)

Valid token → accept page (C-05): org name, role chip, matter name if scoped, expiry note; two paths — "Create your account" (name + password fields, email prefilled and locked, hidden `invitation_token`) posting to the existing `register.store`, and "Already have an account? Sign in" linking to `login` with a `next` back to the token URL. Invalid token → identical generic 404 page in all four failure states (C-06).

### POST `/invitations/{token}/accept` (web)

Existing signed-in-user path (email-bound). Web success → redirect to `/` (app home per 003-D02) with a welcome toast. Failure → the existing generic `invitation_invalid` rendered as a page-level banner on the accept page (no distinguishing detail).

## Error catalog

| Situation | HTTP | Body / page | Audit event |
|---|---|---|---|
| Create invitation, bad email/role | 422 | back with inline field errors | none (rejected pre-mutation) |
| Create invitation, cross-org matter | 404 | generic not-found page | none |
| Revoke accepted invitation | 422 | `{code: validation}` / inline message | none |
| Revoke by non-admin / other org | per existing `RequireOrgAdmin` + org scoping | generic denial | existing admin denial semantics |
| Token invalid/expired/revoked/accepted (guest) | 404 | identical generic page, all states | none (no usable invitation to attribute) |
| Accept with mismatched email/org | 422 | generic "invitation_invalid" banner | `invitation.accept.denied` (already emitted) |

## Audit events

No new events. The service already emits `invitation.created`, `invitation.revoked`, `invitation.accepted`, `matter.grant.created`, and `invitation.accept.denied` — the UI must trigger them by calling the service, never by writing audit rows directly.

## Idempotency & transactions

- Invitation creation is not idempotent by design (each POST mints a new token); double-submit is guarded by standard form UX (disable on submit), not by idempotency keys — out of scope per non-goals.
- Revoke is idempotent (service no-ops on already-revoked).
- Transaction boundaries unchanged (service-owned).

## Role-visible data

| Role | Sees on admin page | Must never see |
|---|---|---|
| org_admin | full invitation table for their org (emails, roles, statuses) | other orgs' invitations; any `token_hash`; plaintext tokens except the single just-created link |
| invitee (guest, valid token) | org name, granted role, matter name if scoped, expiry | anything about other invitations, other users, or the org beyond the invitation |
| invitee (guest, invalid token) | nothing but the generic not-found page | whether the token ever existed |

## External integration failure behavior

Mail is not configured on the server: `InvitationService` queues `InvitationMail`, which will sit unsent. The UI does not depend on mail — the copy-paste accept link (004-D03) is the delivery path for this spec. Configuring mail transport is deferred, not silent: the admin page carries a banner noting "Email delivery is not configured — share the link manually" until it is.

## AI involvement

No AI involvement.
