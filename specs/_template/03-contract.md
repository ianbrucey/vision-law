# <NNN> — <Feature name> — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** <name>
**Date:** <YYYY-MM-DD>
**Schema:** `02-schema-delta.md`

> The contract is settled BEFORE tickets. Routes, DTOs, validation, errors,
> authorization, audit, and idempotency are decided here — not during
> implementation. Views and agents may request only what this contract exposes.

---

## Domain service methods

| Method | Inputs (DTO) | Outputs | Errors |
|---|---|---|---|
| <e.g. `DraftService::proposeSection(draftId, sectionId, instruction, actor)`> | <typed fields> | <e.g. `SectionProposal{section_id, diff, provenance[]}`> | <e.g. `DraftLockedException`, `SectionNotFoundException`> |

<RULE: UI code never mutates persistence directly — all changes pass through
the approved domain service named here.>

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| <e.g. POST> | <e.g. `/api/matters/{matter}/documents/{document}/versions/{n}/restore`> | <controller@method> | <required> | <e.g. `require_matter_access(user, matter, minimum_role: editor)`> |

<RULES:>
- Authenticated-by-default; public exceptions listed explicitly with justification.
- Every route has an authorization rule evaluated server-side BEFORE any data access.
- Denials use 404-not-403 semantics where existence must not leak; otherwise 403.
- No route reads data before its authorization check (verified by the review checklist).

## Requests & validation

### <e.g. POST /api/matters/{matter}/documents>

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `title` | string | yes | max:255 | 422 `{code: "validation", details: {title: ["..."]}}` |
| `direction` | enum | no | in:received,sent | 422 |
| <...> | <...> | <...> | <...> | <...> |

<Every invalid input has a specified error: status code + body shape. No
"whatever the framework does" — write it down.>

## Error catalog

| Situation | HTTP | Body | Audit event |
|---|---|---|---|
| <e.g. version restore with empty reason> | 422 | `{code:"reason_required", message:"..."}` | none (rejected pre-mutation) |
| <e.g. restore by matter viewer> | 403/404 | <per leak rules> | `<resource>.restore.denied` |
| <...> | <...> | <...> | <...> |

## Audit events

| Event | When | Payload |
|---|---|---|
| <e.g. `document.version.restored`> | allow: restore succeeds | `{actor, document_id, from_version, to_version, reason}` |
| <e.g. `document.version.restore.denied`> | deny: authorization fails | `{actor, document_id, attempted_version}` |

<RULE: audit event for allow, deny, read (where sensitive), mutation, export,
and deletion. Application code writes audit events only through `AuditLogger`.>

## Idempotency & transactions

- **Idempotency:** <which POST endpoints accept `Idempotency-Key`; key scope and TTL; what a replay returns>
- **Transaction boundary:** <what is atomic — e.g. "version row + blob reference + audit event commit together; blob upload precedes the transaction and is garbage-collected on rollback">

## Role-visible data

| Role | Sees | Must never see |
|---|---|---|
| <e.g. outside_counsel> | <...> | <e.g. org user directory, other matters' titles> |

<Data returned to each role and data that must never appear — checked by leak
sentinels in tests (HTML, JSON, mail, queue payloads, logs, exports, errors).>

## External integration failure behavior

<If this feature calls an external service: timeouts, retries, circuit
behavior, and the user-visible state when the dependency is down. Failure must
be explicit — never silent success, never silent data loss.>

## AI involvement

<If AI is involved: tool permissions granted, human-approval gate, what the
model may and may not do. If not: "No AI involvement." AI output never
substitutes for deterministic authorization, deadlines, retention, or audit.>
