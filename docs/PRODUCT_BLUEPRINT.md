# Vision Law — Product Blueprint

**Status:** DRAFT — pending product-owner approval
**Owner:** Justice Quest LLC (Ian Bruce)
**Last updated:** 2026-10-06

This document is the canonical product definition for Vision Law. Every feature brief must anchor its claims to this blueprint and to `RFI_REQUIREMENTS_MATRIX.md`. Decisions below are tagged:

- **[D] Decided** — agents may build against it.
- **[P] Proposed** — available for review, not implementation authority.
- **[O] Open** — work that depends on it stops or explicitly defers it.

---

## 1. What Vision Law is

Vision Law is a multi-tenant legal and contract management application built as a **greenfield Laravel 13 application** [D], developed by Justice Quest LLC in direct response to the Georgia Department of Transportation's Request for Information **eRFI-002022-2027, "Legal and Contract Software"** (RFI closes **October 28, 2026**).

It answers three RFI sections:

- **3a — Legal Document Management:** secure creation, upload, preview, versioning, templating, search, retention, and sharing of discovery, court filings, contracts, and other legal documents.
- **3b — Legal Calendaring:** calendaring, task management, reminders, and a data-driven court-deadline rules engine with matter templates that auto-populate discovery and court deadlines.
- **3c — Case & Matter Management:** the matter workspace — lifecycle tracking, parties, comments and audit trail, user assignment, outside-counsel coordination, matter templates, and secure file sharing.

Vision Law is the rebuild vehicle for Justice Quest's earlier legal-tech prototypes. The ~2-year-old Python prototype ("Vision") is **reference implementation only** — its document-ingestion, OCR, and semantic-search designs inform the new build, but no Vision code is carried over as architecture [D]. The Python OCR/chunking pipeline **may** survive as a worker service behind a Laravel-owned interface; that is a per-feature decision, not a platform commitment [P].

### 1.1 Response posture

The RFI explicitly permits "partially" answers. The Oct 28 response strategy is **honest yes + strong partiallies with dated delivery paths** — never an all-"yes" from a vendor with no government customers yet [D]. "Yes" is claimed only where the acceptance criteria in the RFI requirements matrix are met **and demoable**. GovRAMP is the one hard "no": it is a certification process, not a feature.

---

## 2. Who it is for

| Persona | What they do in Vision Law |
|---|---|
| **DOT staff attorney** | Owns matters, reviews and approves drafts, assigns work, manages deadlines and outside counsel, runs disposition review. |
| **Paralegal / legal staff** | Uploads and organizes documents, runs searches, manages tasks and calendar events, prepares drafts for review, applies matter templates. |
| **Org administrator** | Manages users, teams, roles, retention policies, audit exports, SSO/MFA policy, integrations. |
| **Outside counsel** | Matter-scoped external collaborator: sees only invited matters (optionally folder-scoped), cannot enumerate org users or access admin screens; every action is audit-marked `external`. |

Onboarding is **invitation-based** [D] — there is no open self-registration into a government org. (Public self-registration exists only for trial/demo orgs, if offered at all [P].)

---

## 3. Core workflows

Four workflows, in the order a matter actually lives them. The thin vertical slice that proves the system is **matter → document → deadline → share** [D].

### 3.1 Matter → Document

An attorney creates a matter (optionally from a template: pre-filled checklists, requested documents, tasks with computed due dates). Documents attach to the matter: uploaded files (single-shot or chunked/resumable), authored documents written in the built-in editor, or documents generated from templates with merge fields. Every document is scanned, validated, text-extracted or OCR'd, versioned immutably, and full-text searchable — but only ever within the matters the user is authorized to see.

### 3.2 Document → Deadline

Trigger events on a matter (complaint filed, discovery served, custom dates) feed a **data-driven deadline rules engine**: jurisdiction × matter type × trigger → computed deadlines with business-day math against a court-holiday calendar. Rules live in data tables with mandatory legal citations and a `verified` flag; **unverified rules never compute deadlines** [D]. Date changes recalculate dependents transactionally and notify affected users. Reminders fire via a durable notification pipeline with escalation when a deadline passes with its task still open.

### 3.3 Deadline → Share

Matters and documents move outward under control: internal share links for authenticated org users, external expiring links for clients/experts/courts (with upload-back, password, view-limit options), matter-scoped outside-counsel invitations, and encrypted signed-manifest transfer packages for productions. Every share is expirable, revocable, and audit-logged.

### 3.4 Drafting (the intelligence layer)

Agentic drafting assistance operates in **controlled working copies** (see `DRAFTING_ARCHITECTURE.md`): template → structured draft → agent fills sections using matter-document retrieval with citations → HTML preview → section-level human edit or agent revise → approve exact version → commit to the org git repository with `filed`/`sent` tags. AI is a differentiator, never a dependency: the deterministic core (CRUD, calendaring, matter management) works fully without it [D].

---

## 4. The matter as central object

**The matter is the root container and the authorization boundary for all legal work** [D]. Documents, calendar events, tasks, deadlines, comments, message threads, parties, shares, drafts, retention holds, and audit events all belong to exactly one matter. There is no second ownership model: features must not invent competing containers.

- A matter has exactly one lifecycle state at a time: `INTAKE → ACTIVE → DISCOVERY → PRE_TRIAL → TRIAL_SETTLEMENT → CLOSED → RETENTION_HOLD → DISPOSITION`, with only defined transitions allowed.
- Permissions resolve at the matter level: org role + team grants + direct matter grants, with `outside_counsel` default-deny except by explicit grant.
- Authorization failures never leak existence: cross-boundary access returns 404-not-403 semantics [D].
- "Cases" and "matters" are the same thing; the product standardizes on **matter**.

### 4.1 Matter schema (spec 006, frozen 2026-10-07)

One home per fact: the accepted 006 schema delta lives here now
(`specs/006-matter-model/02-schema-delta.md` is the frozen proposal).

- **`matters`** — `matter_number` format `MAT-YYYY-NNNN`, unique per org per
  year (006-D01); generated under `pg_advisory_xact_lock(org_id, year)` +
  MAX+1 (006-D10). `lifecycle_state` CHECK over the 8 states
  (`INTAKE, ACTIVE, DISCOVERY, PRE_TRIAL, TRIAL_SETTLEMENT, CLOSED,
  RETENTION_HOLD, DISPOSITION`), default `INTAKE` — replaces the 001 `status`
  column (006-D02, `open` → `INTAKE` backfill). `matter_type` CHECK over
  `litigation, transactional, regulatory, employment, real_estate,
  estate_planning, other`. `title`, `client_name` (confidential display
  convenience; structured data lives in `matter_parties`), `description`
  (work-product), `closed_at` (set on CLOSED, cleared on reopen),
  `deleted_at` soft delete (recoverable by org admins ≤ 30 days).
  `template_id`/`template_version` reserved as the Phase 4 interface (006-D07,
  no FK yet). Indexes: `UNIQUE(org_id, matter_number)`, `(org_id,
  lifecycle_state) WHERE deleted_at IS NULL`, trigram GIN
  (`matters_search_trgm`) over title/client_name/matter_number.
- **`matter_parties`** — 7 types (006-D14:
  `client, opposing_party, opposing_counsel, witness, expert, court, other`);
  name (confidential) + role_description/email/phone/address + optional
  `user_id` link; soft delete.
- **`matter_comments`** — markdown body (work-product), one-level threading
  (`parent_id` must be top-level), author edit within 24h (`edited_at` badge),
  soft delete → tombstone (row + author retained). `created_at` and
  `matter_comment_reads.last_read_at` are `timestamptz(6)` so unread counts
  are deterministic (006-D15).
- **`matter_comment_reads`** — `UNIQUE(matter_id, user_id)`, `last_read_at`;
  internal telemetry, powers MAT-16 unread counts.
- **`matter_links`** — bidirectional related matters, canonical
  `matter_id < related_matter_id`, typed
  (`same_client, consolidated, appeal, companion, other`), self-link rejected,
  `UNIQUE(org_id, matter_id, related_matter_id)`. Restricted matters render
  as "restricted matter" with no title/metadata leak.
- **`matter_document_log`** — the received/sent register (NOT document
  storage — Phase 3). Append-only by design: no update/delete routes, core
  fields immutable, corrections are new rows, `annotations` (jsonb) the only
  mutable field. `direction` (`received, sent`) and `method`
  (`upload, email, share_link, integration`) CHECKs. Phase 3 may add a
  nullable `document_id` FK.
- **`matter_grants`** — unchanged; assignment rides on 001's table (006-D05).
  Contract roles (`owner, editor, viewer, outside_counsel`) reconciled with
  legacy names in `AccessControl::ROLE_RANK` — `owner` ≡ `matter_owner`
  (rank 5), `outside_counsel` between `viewer` (1) and `editor` (3) (006-D11).
  Last-owner protection is rank-based on both the 006 service path (006-D16)
  and the 001 grants path (006-D17).
- **Activity feed** — a read model over `audit_events` (006-D04); no separate
  table. Every 006 mutation writes its audit row in the same transaction.
- **Lifecycle rules** — the transition table is contract data (migration-free
  changes); invalid transition → 409 with legal next states; same-state
  transition → 200 no-op (006-D06); `INTAKE → ACTIVE` requires a `client`
  party; guided closure requires a closing note; CLOSED matters are read-only
  except reopen/transition.

---

## 5. Architecture decisions

### 5.1 Platform [D]

- **Greenfield Laravel 13** application (repo `ianbrucey/vision-law`), API-first; the web UI consumes the same API agents and integrations use.
- **Multi-tenant from day one:** org → teams → matters scoping on every query; tenant keys on every tenant-owned table; cross-tenant access impossible by construction.
- **PostgreSQL** with pgvector for semantic retrieval; CI and tests run against PostgreSQL, never SQLite.
- Native services preferred over Docker where operationally sane (Postgres runs in Docker on the shared dev server today; the app itself runs natively).
- **Drafting intelligence via Laravel 13's first-party AI SDK** (agents + tools + human-in-the-loop + pgvector) [D]; the Python OCR/chunking pipeline may remain a worker service behind Laravel-owned interfaces [P].
- **No framework or AI SDK decision substitutes for drafting mechanics** — see `DRAFTING_ARCHITECTURE.md`, which must be approved before drafting work is distributed.

### 5.2 Data-driven rules [D]

Court deadlines, retention schedules, matter templates, and the RBAC permission matrix live in **data tables / configuration files, not code**. Rule accuracy for court deadlines is verified against primary sources (statutes, court rules) before any rule ships; every rule carries its citation.

### 5.3 AI governance [D]

- Model providers are called only through an **AI gateway**; privileged tools require recorded human approval.
- Every AI-generated answer or draft section is **permission-scoped** and carries **citations to source documents**; fixed synthetic matters evaluate tool choice, provenance, and abstention — never exact prose.
- AI output never substitutes for deterministic authorization, deadline math, retention logic, or audit.
- The same core workflow remains usable without AI (RFI Q6: capable without AI except semantic search, agent drafting, intake extraction).

---

## 6. Non-negotiables

These hold regardless of phase, schedule pressure, or RFI positioning.

1. **[D] Server-side matter-level authorization.** Every matter-scoped endpoint calls one centralized authorization check before any data access. No client-side filtering as a security boundary. 404-not-403 semantics on denial.
2. **[D] Append-only audit.** Every mutation — and every sensitive read — writes an immutable audit record (hash-chained, DB-enforced insert-only). If it isn't logged, it didn't happen. Audit includes actor, action, object identity, before/after, IP, and timestamp.
3. **[D] Git working-copy drafting.** Drafts are developed on branches in the organization's git repository; **merge = approval**; history = audit trail. Filed and released drafts are tagged (`filed`, `sent`); approved versions are immutable.
4. **[D] Retention and legal holds that actually hold.** A legal hold blocks hard delete, version purge, matter archival, and disposition (HTTP 423 / 409). Holds survive reassignment and user deactivation. Destruction requires dual control (two distinct approvers) and leaves a permanent tombstone.
5. **[D] Thin-real integrations first.** Ship working narrow integrations (OAuth + file pull/push + export) before deep ones. Never demo vapor; never claim an integration that is a mock.
6. **[D] Deterministic legal math.** Deadline computation is pure, deterministic, and unit-tested; no AI in the deadline path. Unverified rules are excluded from computation.
7. **[P] Rendering fidelity without content mutation.** The renderer converts the canonical draft to HTML/PDF/DOCX; rendering must never alter content. PDF via headless Chromium; DOCX export preserves document styles.

---

## 7. Decision register (claims index)

| # | Claim | Tag |
|---|---|---|
| D-01 | Greenfield Laravel 13; Laravel owns HTTP/auth/jobs/UI/git orchestration | [D] |
| D-02 | Matter is the single central object and authorization boundary | [D] |
| D-03 | Multi-tenant org → teams → matters; cross-tenant access impossible by construction | [D] |
| D-04 | Server-side matter-level authorization; 404-not-403 semantics | [D] |
| D-05 | Append-only, hash-chained audit; DB-enforced insert-only | [D] |
| D-06 | Git working-copy drafting: branch = draft, merge = approval, history = audit | [D] |
| D-07 | Legal holds block deletion/disposition; dual-control destruction with tombstone | [D] |
| D-08 | Deadline rules are data with mandatory citations; unverified rules never compute | [D] |
| D-09 | Drafting intelligence via Laravel 13 first-party AI SDK (agents + tools + HITL + pgvector) | [D] |
| D-10 | Thin-vertical-slice delivery: matter → upload → deadline → share demonstrable early | [D] |
| D-11 | Invitation-based onboarding for customer orgs | [D] |
| D-12 | Thin-real integrations before deep ones; no vapor demos | [D] |
| P-01 | Python OCR/chunking pipeline may survive as a worker service behind Laravel interfaces | [P] |
| P-02 | Rendering pipeline: structured draft → HTML → PDF via headless Chromium; DOCX export with styles | [P] |
| P-03 | Public self-registration limited to trial/demo orgs, if offered | [P] |
| O-01 | **Exact draft document serialization format** (structured DB sections vs. document file in repo vs. hybrid with authority rule) | [O] |
| O-02 | **Merge / coauthoring UX** (how concurrent human edits reconcile; what the review surface looks like) | [O] |
| O-03 | **AI provider choice** (which model provider(s) back the AI gateway; cost/latency/data-boundary tradeoffs) | [O] |

Work that depends on an [O] item stops or explicitly defers it. [P] items are reviewable but not implementation authority.

---

## 8. Open questions for the product owner

1. Which jurisdictions' deadline rules ship first? (Proposed: Georgia state civil + federal civil.)
2. Who verifies deadline rules against primary sources, and by when?
3. Pricing/TCO model: per-user SaaS tiers? Per-matter? Implementation package?
4. Retention schedules: encode a Georgia/agency records schedule, or ship configurable defaults?
5. Matter numbering scheme: `YYYY-NNNN` per org, or customer-defined?
6. Outside counsel authentication: email-invite + password, or federated IdP (SAML)?
7. Storage backend: S3-compatible (which region/residency?) vs. self-hosted?
8. OCR provider: cloud API (data-boundary acceptable?) vs. self-hosted?
9. E-signature: in scope for a later phase, or out entirely?
10. Client portal: is the external share-link model sufficient, or is a full client portal expected?

---

## 9. Source map

- RFI coverage and requirement detail: `docs/RFI_REQUIREMENTS_MATRIX.md`
- Build order and timeline: `docs/ROADMAP.md`
- Drafting mechanics: `docs/DRAFTING_ARCHITECTURE.md`
- Build discipline (briefs → contracts → tickets → review → freeze): `docs/Planning_Protocol.md`, `docs/Build_Protocol.md`
- Per-feature work: `specs/<NNN>-<slug>/` (see `specs/_template/`)
