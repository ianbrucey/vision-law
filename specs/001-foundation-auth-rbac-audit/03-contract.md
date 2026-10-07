# 001 — Foundation: authentication, RBAC, and audit logging — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-06
**Schema:** `02-schema-delta.md`

> The contract is settled BEFORE tickets. Views and agents may request only
> what this contract exposes. Fortify provides the auth backend; this contract
> pins its configuration and everything around it.

---

## Domain service methods

| Method | Inputs (DTO) | Outputs | Errors |
|---|---|---|---|
| `AccessControl::authorize(User $user, string $action, object $object): void` | user, action (`view|update|grant|admin`), object (Matter, User, Organization…) | void (throws on denial) | `AccessDeniedException` (→ 403 or 404 per leak rules) |
| `AccessControl::effectiveMatterRole(User $user, Matter $matter): ?string` | user, matter | `matter_owner|matter_admin|editor|viewer|null` | — (null = no access) |
| `AuditLogger::log(string $event, ?User $actor, array $payload, ?Matter $matter = null): AuditEvent` | event name, actor, payload, optional matter | the created `AuditEvent` | `InvalidPayloadException` if payload contains privileged keys (password, secret, token, hash) |
| `InvitationService::invite(Organization $org, string $email, string $role, ?Matter $matter, User $invitedBy): Invitation` | org, email, role, optional matter, inviter | `Invitation` (token sent by mail, only hash stored) | `ValidationException` (bad role/email) |
| `InvitationService::accept(string $token, User $user): void` | token, signed-in user | void | `InvitationInvalidException` (expired/revoked/mismatched user → generic message) |

<RULE: UI code never mutates persistence directly — user/team/grant changes go through these services or Fortify actions. Audit rows are written ONLY by `AuditLogger`.>

## Permission matrix (single source of truth)

Seeded from this table; `outside_counsel` is default-deny on matters without explicit grants.

| Capability | org_admin | attorney | paralegal | outside_counsel | viewer |
|---|---|---|---|---|---|
| Manage org users/roles/teams/invitations | ✅ | ❌ | ❌ | ❌ | ❌ |
| View audit log | ✅ | ❌ | ❌ | ❌ | ❌ |
| Create matter (stub: title/number) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Matter actions at/above grant role | ✅* | per grant | per grant | per grant (explicit only) | per grant |
| List org user directory | ✅ | ✅ | ✅ | ❌ | ✅ |

\* `org_admin` has implicit `matter_owner` on all org matters.

Matter role hierarchy: `matter_owner > matter_admin > editor > viewer`. Effective permission = most permissive of (org default from matrix, team grants, direct grants), except `outside_counsel` which ignores org default.

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `/register` | `RegisteredUserController@create` | guest | — |
| POST | `/register` | `RegisteredUserController@store` | guest | invitation token or open registration per org settings |
| GET | `/login` | `AuthenticatedSessionController@create` | guest | — |
| POST | `/login` | `AuthenticatedSessionController@store` | guest | rate-limited (5/15 min per account+IP) |
| POST | `/logout` | `AuthenticatedSessionController@destroy` | auth | — |
| GET/POST | `/forgot-password`, `/reset-password/{token}` | Fortify password reset | guest | rate-limited; generic responses |
| GET | `/email/verify/{id}/{hash}` | Fortify verification | auth, signed | — |
| POST | `/email/verification-notification` | Fortify | auth | throttled |
| GET/POST | `/two-factor-challenge` | Fortify 2FA | guest (challenged) | — |
| GET | `/user/two-factor-authentication` etc. | Fortify MFA management | auth + `password.confirm` | self only |
| GET | `/invitations/{token}` | `InvitationController@show` | guest | token valid, not expired/revoked |
| POST | `/invitations/{token}/accept` | `InvitationController@accept` | auth | signed-in user's email matches invitation email |
| GET | `/sessions` | `SessionController@index` | auth | self only |
| DELETE | `/sessions/{id}`, `/sessions` | `SessionController@destroy`, `destroyAll` | auth + `password.confirm` | self only |
| GET/POST | `/admin/users`, `/admin/users/{user}`, `PUT/PATCH`, `DELETE` | `Admin\UserController` | auth | `org_admin` (403 otherwise) |
| GET/POST | `/admin/teams`, `/admin/teams/{team}` … | `Admin\TeamController` | auth | `org_admin` |
| GET/POST | `/admin/invitations`, `DELETE /admin/invitations/{invitation}` | `Admin\InvitationController` | auth | `org_admin` |
| GET | `/admin/audit-events` | `Admin\AuditEventController@index` | auth | `org_admin` (403 otherwise) |
| GET | `/admin/audit-events/export` | `Admin\AuditEventController@export` | auth + `password.confirm` | `org_admin`; watermarked |
| GET | `/matters/{matter}` | `MatterController@show` (minimal) | auth | `RequireMatterAccess:view` → 404 if no grant / cross-org |
| POST | `/matters/{matter}/grants` | `MatterGrantController@store` | auth | `RequireMatterAccess:grant` (matter_admin+) |
| DELETE | `/matters/{matter}/grants/{grant}` | `MatterGrantController@destroy` | auth | `RequireMatterAccess:grant`; cannot remove the last `matter_owner` |

<RULES:>
- Authenticated-by-default; the guest list above is exhaustive.
- Every route evaluates its authorization rule server-side BEFORE any data access.
- **404-not-403:** cross-org access and matter access without any grant → 404. Insufficient role on a visible object → 403.
- No route reads data before its authorization check (review checklist in T-07).

## Requests & validation

### POST /register

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `name` | string | yes | max:255 | 422 `{code:"validation", details:{name:[...]}}` |
| `email` | email | yes | max:255, unique per org | 422 (duplicate → **generic** "If this email is available, a verification link was sent" — no enumeration) |
| `password` | string | yes | min:12, breached-password check | 422 |
| `invitation_token` | string | conditional | valid, unexpired, email-bound | 422 `invitation_invalid` (generic) |

### POST /matters/{matter}/grants

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `subject_type` | enum | yes | in:user,team | 422 |
| `subject_id` | uuid | yes | exists in org | 422 |
| `role` | enum | yes | in:matter_owner,matter_admin,editor,viewer | 422 |
| `expires_at` | datetime | no | after:now | 422 |

All invalid input → 422 with `{code:"validation", details:{...}}`. No stack traces or SQL text in any error body.

## Error catalog

| Situation | HTTP | Body | Audit event |
|---|---|---|---|
| Login with wrong password | 422 | `{code:"invalid_credentials"}` (identical for unknown email) | `auth.login.failed` |
| 5th failed login | 429 | `{code:"locked_out", retry_after: 900}` | `auth.login.locked_out` |
| Matter access without grant / cross-org | 404 | `{code:"not_found"}` | `matter.access.denied` |
| Viewer attempts matter write | 403 | `{code:"forbidden"}` | `matter.access.denied` |
| Non-admin hits `/admin/*` | 403 | `{code:"forbidden"}` | `user.admin.denied` |
| Invitation accept by wrong signed-in user | 422 | `{code:"invitation_invalid"}` (generic) | `invitation.accept.denied` |
| Audit export by non-admin | 403 | `{code:"forbidden"}` | `audit.viewer.denied` |
| Validation failure (any) | 422 | `{code:"validation", details:{...}}` | none (rejected pre-mutation) |

## Audit events

| Event | When | Payload |
|---|---|---|
| `auth.login` | allow: login succeeds | `{actor_id, ip, mfa_used: bool}` |
| `auth.login.failed` | deny: bad credentials (no user enumeration in payload) | `{ip, email_domain_digest}` |  <!-- 001-D12: renamed from email_domain_hash; the AuditLogger privileged-key blocklist rejects any key containing 'hash' -->
| `auth.login.locked_out` | deny: lockout triggered | `{ip}` |
| `auth.logout` | allow | `{actor_id}` |
| `auth.mfa.enabled` / `auth.mfa.disabled` | allow | `{actor_id}` |
| `auth.password.reset` | allow | `{actor_id}` (sessions revoked count) |
| `user.created` | allow: registration or invite-accept | `{actor_id, org_id, role}` |
| `user.role.assigned` | allow | `{actor_id, target_user_id, role, granted_by}` |
| `invitation.created` / `invitation.accepted` / `invitation.revoked` | allow | `{org_id, email, role, invited_by}` |
| `matter.grant.created` / `matter.grant.revoked` | allow | `{actor_id, matter_id, subject, role, granted_by}` |
| `matter.access.denied` | deny: 403 or 404 on matter routes | `{actor_id, matter_id, attempted_action}` |
| `session.revoked` / `session.revoked_all` | allow | `{actor_id, revoked_count}` |
| `audit.exported` | allow: audit CSV/PDF export | `{actor_id, filters, watermark}` |

<RULE: `AuditLogger` rejects payloads containing keys matching `password|secret|token|hash|recovery` — privileged data never reaches the audit table.>

## Idempotency & transactions

- **Idempotency:** invitation-accept and grant-create accept `Idempotency-Key` (scope: org, TTL 24 h); replay returns the original result.
- **Transaction boundary:** user-create + role-assign + audit event commit atomically; grant-create + audit event atomically; audit insert and its hash-chain link are one statement (chain computed in-transaction under the per-org advisory lock).

## Role-visible data

| Role | Sees | Must never see |
|---|---|---|
| `org_admin` | org user directory, teams, invitations, full audit log | other orgs' data; privileged fields (hashes, secrets) |
| `attorney` / `paralegal` / `viewer` | org user directory (names/emails), matters they hold grants on | audit log; other orgs' data; privileged fields |
| `outside_counsel` | matters they hold explicit grants on; grantor names | org user directory; other matters' titles; audit log |
| `anonymous` | login/register/reset/invitation-accept screens only | everything else (→ redirect `/login`) |

## External integration failure behavior

Auth mail (verification, reset, invitation) is queued. If the mail queue is down: registration still completes; the user lands in `pending_verification` with a "resend" action — never a silent success that implies an email was sent. Breached-password check (k-anonymity API) failing open/closed: **fail closed** (reject the password attempt with a retryable error) — documented choice, revisit if availability suffers.

## AI involvement

No AI involvement. AI output never substitutes for deterministic authorization or audit.
