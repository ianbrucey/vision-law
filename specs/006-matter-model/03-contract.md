# 006 — Matter model and lifecycle — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Schema:** `02-schema-delta.md`

> UI code never mutates persistence directly — all changes pass through `MatterService`. Every route below names its authorization rule, evaluated server-side BEFORE any data access. Denials use 404-not-403 where existence must not leak.

---

## Lifecycle transition table (MAT-02)

| From | To | Guard |
|---|---|---|
| INTAKE | ACTIVE | ≥ 1 non-deleted `client` party, else 422 `client_party_required` |
| INTAKE | CLOSED | closing note required (declined at intake) |
| ACTIVE | DISCOVERY | — |
| ACTIVE | CLOSED | guided closure (closing note required) |
| DISCOVERY | PRE_TRIAL | — |
| DISCOVERY | CLOSED | guided closure |
| PRE_TRIAL | TRIAL_SETTLEMENT | — |
| PRE_TRIAL | CLOSED | guided closure |
| TRIAL_SETTLEMENT | CLOSED | guided closure |
| CLOSED | ACTIVE | reopen note required |
| CLOSED | RETENTION_HOLD | org admin only; note required (Phase 5 nightly job also drives this) |
| RETENTION_HOLD | DISPOSITION | **Phase 5** — 006 returns 409 `disposition_not_enabled` |

Any other pair → 409 `{code: "invalid_transition", legal_next_states: [...]}`. Transition to the current state → 200 no-op (no audit row). Concurrent transitions serialize via `SELECT … FOR UPDATE` on the matter row inside the transition transaction (transition + `closed_at` maintenance + audit row commit atomically).

CLOSED matters are read-only: any mutation except transition/restore → 409 `{code: "matter_closed"}`.

## Domain service methods (`MatterService`)

| Method | Inputs | Outputs | Errors |
|---|---|---|---|
| `createMatter(orgId, {title, matter_type, client_name, description?}, actor)` | validated attrs | `Matter` with generated `MAT-YYYY-NNNN` | 422 validation; number race retried under `pg_advisory_xact_lock(org_id, year)` |
| `updateMatter(matter, attrs, actor)` | partial attrs | `Matter` | 422 validation; 409 if CLOSED |
| `deleteMatter(matter, actor)` | — | soft-deleted | 409 if CLOSED (close first); 403 unless owner/org_admin |
| `restoreMatter(matter, actor)` | — | restored | 422 `restore_window_expired` if deleted > 30d; org_admin only |
| `transitionMatter(matter, to, note?, actor)` | state, note | `Matter` | 409 invalid (+ legal next states); 422 guard failures |
| `closeMatter(matter, note, actor)` | note (required) | CLOSED matter | 422 `closing_note_required`; dispatches `MatterClosed` event (Phase 5 hook, no listener in 006) |
| `assignUser(matter, userId, role, expiresAt?, actor)` | grant attrs | `MatterGrant` | 422 `last_owner` if it would remove/demote the final owner; owner or org_admin only |
| `unassignUser(matter, grantId, actor)` | — | void | same guards |
| `addParty / removeParty` | party attrs | `MatterParty` | 422 validation |
| `addComment / editComment (24h) / deleteComment (tombstone)` | body, parent? | `MatterComment` | 422 `reply_depth_exceeded` (parent has parent); 422 `edit_window_expired`; @mentions parsed → `matter.mentioned` audit |
| `addLink / removeLink` | related id, type, note | `MatterLink` | 422 `self_link`; canonical ordering enforced |
| `logDocument(matter, {direction, counterparty, logged_at, method, notes?}, actor)` | log attrs | `MatterDocumentLog` | 422 validation; core fields immutable after insert |
| `searchMatters(org, user, query?, filters?)` | q, state/type/assignee/client/updated_since | paginator, permission-scoped | — |
| `statusSummary(matter, user)` | — | derived array (see below) | — |
| `markTimelineRead(matter, user)` | — | void | upserts `matter_comment_reads` |

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `/matters` | MatterController@index | required | `MatterPolicy::viewAny` — org_admin: all org matters; others: matters with a valid grant ("My Matters") |
| GET | `/matters/create` | MatterController@create | required | `create`: attorney, org_admin, paralegal (006-D03) |
| POST | `/matters` | MatterController@store | required | same as create |
| GET | `/matters/{matter}` | MatterController@show | required | `matter.access:view` |
| GET | `/matters/{matter}/edit` | MatterController@edit | required | `matter.access:edit` |
| PATCH | `/matters/{matter}` | MatterController@update | required | `matter.access:edit` |
| DELETE | `/matters/{matter}` | MatterController@destroy | required | `matter.access:manage` (owner/org_admin) |
| POST | `/matters/{matter}/restore` | MatterController@restore | required | org_admin |
| POST | `/matters/{matter}/transition` | MatterController@transition | required | `matter.access:manage` |
| POST | `/matters/{matter}/close` | MatterController@close | required | `matter.access:manage` |
| GET | `/matters/{matter}/summary` | MatterController@summary | required | `matter.access:view` (JSON, derived) |
| POST | `/matters/{matter}/parties` | PartyController@store | required | `matter.access:edit` |
| DELETE | `/matters/{matter}/parties/{party}` | PartyController@destroy | required | `matter.access:edit` (soft delete) |
| POST | `/matters/{matter}/comments` | CommentController@store | required | `matter.access:comment` |
| PATCH | `/matters/{matter}/comments/{comment}` | CommentController@update | required | author (24h) or `matter.access:manage` |
| DELETE | `/matters/{matter}/comments/{comment}` | CommentController@destroy | required | author or `matter.access:manage` (tombstone) |
| POST | `/matters/{matter}/assignments` | AssignmentController@store | required | `matter.access:manage` |
| DELETE | `/matters/{matter}/assignments/{grant}` | AssignmentController@destroy | required | `matter.access:manage` (+ last-owner guard) |
| POST | `/matters/{matter}/links` | LinkController@store | required | `matter.access:edit` |
| DELETE | `/matters/{matter}/links/{link}` | LinkController@destroy | required | `matter.access:edit` |
| POST | `/matters/{matter}/document-log` | DocumentLogController@store | required | `matter.access:edit` |

Action levels: `view` < `comment` < `edit` < `manage`; `grant` (existing) ≈ `manage` for assignments. Outside counsel: explicit grant only, default-deny.

## Requests & validation (selection; all 422 `{code:"validation", details:{…}}`)

- `POST /matters`: `title` required max:500; `matter_type` required in:litigation,transactional,regulatory,employment,real_estate,estate_planning,other; `client_name` required max:255; `description` nullable.
- `POST /matters/{matter}/transition`: `to` required in:8 states; `note` required when `to` is CLOSED or from CLOSED.
- `POST parties`: `party_type` in 6 types; `name` required; `email` nullable email; `user_id` nullable exists:users.
- `POST comments`: `body` required max:20000; `parent_id` nullable exists + must be top-level.
- `POST assignments`: `user_id` required exists (same org); `role` in:owner,editor,viewer,outside_counsel; `expires_at` nullable future.
- `POST links`: `related_matter_id` required exists, ≠ matter; `link_type` in 5 types.
- `POST document-log`: `direction` in:received,sent; `counterparty` required; `logged_at` date; `method` in:upload,email,share_link,integration.

## Error catalog

| Situation | HTTP | Body | Audit event |
|---|---|---|---|
| Invalid transition | 409 | `{code:"invalid_transition", legal_next_states:[…]}` | `matter.transition.denied` |
| INTAKE→ACTIVE without client party | 422 | `{code:"client_party_required"}` | `matter.transition.denied` |
| Demote/remove final owner | 422 | `{code:"last_owner"}` | `matter.assign.denied` |
| Reply to a reply | 422 | `{code:"reply_depth_exceeded"}` | none (pre-mutation) |
| Comment edit after 24h | 422 | `{code:"edit_window_expired"}` | none |
| Self link | 422 | `{code:"self_link"}` | none |
| Restore after 30d | 422 | `{code:"restore_window_expired"}` | `matter.restore.denied` |
| Mutation on CLOSED matter | 409 | `{code:"matter_closed"}` | `matter.access.denied` |
| Unassigned/other-org/anonymous access | 403/404 | `{code:"forbidden"}` / `{code:"not_found"}` | `matter.access.denied` |
| RETENTION_HOLD→DISPOSITION | 409 | `{code:"disposition_not_enabled"}` | none (Phase 5) |

## Audit events

Every allow writes through `AuditLogger` with `$matter` set, in the same transaction as the mutation: `matter.created`, `matter.updated`, `matter.deleted`, `matter.restored`, `matter.transition` (`{from,to,note}`), `matter.closed`, `matter.reopened`, `matter.party.added/removed`, `matter.comment.added/edited/deleted`, `matter.mentioned` (`{mentioned_user_ids}`), `matter.assigned/unassigned` (`{role, expires_at}`), `matter.link.added/removed`, `matter.document_logged`. Denials: `matter.access.denied` (middleware), `matter.transition.denied`, `matter.assign.denied`, `matter.restore.denied`.

## Status summary (MAT-16) — derived shape

`{lifecycle_state, days_in_state, document_log:{received,sent}, tasks:{open:0,overdue:0} /* Phase 4 */, deadlines_next_14d:[] /* Phase 4 */, unread_comments, last_activity:{at,actor,event}, assigned_team:[{user,role}], active_holds:[] /* Phase 5 */}` — computed on read; no status table.

## Role-visible data

| Role | Sees | Must never see |
|---|---|---|
| org_admin | all org matters, full directory | other orgs' data |
| attorney/paralegal/editor/viewer (granted) | granted matters: full detail per grant | ungranted matters (404), other orgs |
| outside_counsel | granted matters only; no user directory, no admin screens | ungranted matters, org directory |
| anonymous | nothing | everything (all routes 302→login or 404) |

Related matters the user can't access render as `{restricted: true}` — no title, no metadata (leak sentinel test).

## Idempotency & transactions

- Transition to current state → 200 no-op, no audit row.
- Each mutation + its audit row commit in one transaction; transitions hold `SELECT … FOR UPDATE`.
- `POST /matters` number generation is race-safe via `pg_advisory_xact_lock`; no `Idempotency-Key` in 006 (deferred with 001).

## AI involvement

No AI involvement. Deadlines, authorization, and audit remain deterministic.
