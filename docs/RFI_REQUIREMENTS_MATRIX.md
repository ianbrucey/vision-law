# Vision Law — RFI Requirements Traceability Matrix

**RFI:** Georgia Department of Transportation, eRFI-002022-2027, "Legal and Contract Software" (closes Oct 28, 2026)
**Owner:** Justice Quest LLC
**Status:** Living document — update item status as build progresses.

This is the backbone every feature traces to. Each requirement carries its original ID (`DOC-`, `CAL-`, `MAT-`, `PLT-`), a condensed-but-faithful statement of the requirement, its RFI section trace, and a status. All items start at `planned`; phase-2 items are marked `planned (Phase 2)`.

**Conventions:** "Matter" = case/contract/engagement (the specs standardize on "matter"). "Platform spec" = the cross-cutting foundation (auth, orgs/teams, RBAC, encryption, audit). All timestamps UTC. All file sizes in MiB/GiB.

---

## Section 1 — Document Management (DOC-01 … DOC-30)

Covers RFI §3a: a.a create/edit/access secure documents + templates · a.b collaborate with authorized users · a.c access from anywhere, secure use · a.d secure storage, file transfer, maintenance · a.e retention policy flagging · a.f version control, search, rollback.

| ID | Requirement (condensed) | RFI trace | Status |
|---|---|---|---|
| DOC-01 | Single-shot file upload up to 100 MiB in one request; server streams bytes to object storage, computes SHA-256 during streaming, sniffs MIME type (never trusts extension), dedupes identical bytes to one blob; >100 MiB rejected with 413 before storage. | a.a, a.d | planned |
| DOC-02 | Chunked/resumable upload for 100 MiB–5 GiB in 8 MiB chunks: initiate session → idempotent per-chunk PUT → resume via received-chunk bitmap → complete with total SHA-256 verification (422 on mismatch, session retained) → cancel with garbage collection; sessions expire after 24 h idle. | a.a, a.d | planned |
| DOC-03 | Virus/malware scan of every upload before availability (ClamAV daemon default, engine configurable); infected → `quarantined`, blob moved to inaccessible quarantine prefix, audit event + admin notification; engine unreachable → uploads stay in `scanning`, never silently marked clean, ops alert after 5 min. | a.c, a.d | planned |
| DOC-04 | MIME allowlist validation (PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, CSV, PNG, JPG, TIFF, MSG/EML) plus technical metadata extraction: page count, image dimensions/DPI, document properties, native-text vs image-only detection (routes image-only to OCR); failures set `metadata_status: partial` without blocking. | a.a, a.f | planned |
| DOC-05 | In-browser PDF preview via pdf.js: page navigation, thumbnail strip, zoom 50–400%, find-in-page, text selection; served through short-lived (15 min) signed URLs scoped to the user; permission re-checked on every range request so revoked access invalidates outstanding URLs. | a.a, a.c | planned |
| DOC-06 | Office (DOCX/XLSX/PPTX) preview by server-side conversion to PDF (LibreOffice headless), cached as a derived artifact linked to the source version; cache invalidated on new version; conversion failures surface a graceful "preview unavailable — download instead" state. | a.a, a.c | planned |
| DOC-07 | Image (PNG/JPG/TIFF) preview with zoom-to-fit/100%/+, 90° rotate, page navigation for multi-page TIFFs; large images load progressively/downscaled, original fetched only on deep zoom or download. | a.a, a.c | planned |
| DOC-08 | Download of exact original bytes for any version, with original filename, `Content-Disposition: attachment`, and `X-Checksum-Sha256` integrity header; every download writes an audit event. | a.a, a.d | planned |
| DOC-09 | Built-in rich-text editor to author documents from scratch (pleadings, letters, memos): headings, lists, tables, page-break hints, pleading line-numbering toggle; autosave to draft state every 30 s; explicit Save publishes version 1 as structured HTML + generated PDF rendition. | a.a | planned |
| DOC-10 | Content editing for authored/template documents (each save = new immutable version with optional change note) and metadata editing (title, description, tags, folder, matter) for any document without creating a version; uploaded binaries cannot be content-edited in-app — UI offers "upload new version". | a.a, a.f | planned |
| DOC-11 | Soft delete → per-matter trash (30 days) → scheduled hard delete; legal hold blocks hard delete; trash visible only to users with matter delete permission; restore returns all versions intact. | a.d, a.e | planned |
| DOC-12 | Per-matter folder tree (create/rename/move, drag-and-drop, delete empty-only, bulk move/tag/download-as-ZIP preserving structure); default folder set seeded per matter type (Pleadings, Discovery, Correspondence, Contracts). | a.a | planned |
| DOC-13 | Every document belongs to exactly one matter; reassignment (`move-matter`) carries full version history and audit trail; permissions re-evaluated against the destination matter at move time. | a.b | planned |
| DOC-14 | Document list with faceted filtering (type, folder, uploader, date range, retention-flag status, version count) and keyword search over title/filename/tags/description/uploader (trigram/FTS); per-user saved views; 50/page pagination with totals. | a.b, a.f | planned |
| DOC-15 | Text extraction pipeline for native-text documents: per-page text rows linked to the document version, Postgres tsvector FTS index; idempotent per version (re-runs are no-ops); documents with no extractable text flagged `needs_ocr`. | a.f | planned |
| DOC-16 | OCR for scanned/image-only documents via configured provider (300 DPI page images): per-word confidence scores, low-confidence pages flagged in UI, OCR text feeds the search index and is downloadable as a text layer; per-page cost logged per document. | a.a, a.f | planned |
| DOC-17 | Full-text content search across a matter: ranked results with per-page snippets and match counts; click opens preview at the matched page with hits highlighted; phrase and exclusion query support; strictly permission-scoped results. | a.b, a.f | planned |
| DOC-18 | Immutable content-addressed version control: every content change creates a new `DocumentVersion` with gapless sequential numbers; blobs deduplicated by SHA-256; byte-identical uploads are a no-op with notice; metadata-only edits never create versions. | a.f | planned |
| DOC-19 | Version history view: all versions by date, author, size, change note (newest first), current version distinguished; per-row actions: preview, download, restore, diff-against-current. | a.f | planned |
| DOC-20 | Rollback: restoring a prior version creates a new version N+1 with the old version's exact bytes (history never rewritten), requires a non-empty reason, records `restored_from_version`, permission-gated to matter editors. | a.f | planned |
| DOC-21 | Version diff: structured added/removed/changed diff with page hints, side-by-side or unified rendering, for text versions; clean "diff unavailable" state for image-only versions without OCR text. | a.f | planned |
| DOC-22 | Template library: org-level reusable templates authored as HTML with `{{merge_field}}` placeholders and typed field definitions (text/date/number/party/matter_field, required, default); templates versioned like documents; publishing requires the template-editor role. | a.a | planned |
| DOC-23 | Generate document from template: field form rendered from template definitions, `matter_field` types pre-filled from the matter record, required-field validation; creates an authored Document v1 linked to the source template version for traceability; generated document fully editable thereafter. | a.a | planned |
| DOC-24 | Internal sharing: document-level grants to users/teams at viewer/commenter/editor; effective permission = max(matter role, document grant); grant list visible per document; all grants audited; matter-access revocation kills document access at read time. | a.b, a.c | planned |
| DOC-25 | External secure link sharing: unguessable token URLs with expiry (default 7 days, max 30), optional password (rate-limited: 5 attempts → 15 min lockout) and download toggle, pinned to a version; served from a hardened separate endpoint; every access audited with IP/timestamp; instant revocation. | a.b, a.c, a.d | planned |
| DOC-26 | Secure transfer/export package: selected documents/folders assembled into an AES-256-encrypted ZIP with a signed JSON manifest (document IDs, versions, per-file SHA-256, exporter, timestamp); recipient verifies completeness/integrity independently; streaming assembly for large sets. | a.d | planned |
| DOC-27 | Retention policy definitions: admin-authored rules per document category and matter type (retention period, trigger: matter_close/document_date/fixed_date, disposition: destroy/review/archive, legal basis); policies versioned; a simulator previews which existing documents a draft policy would affect before activation. | a.e | planned |
| DOC-28 | Retention flagging and legal hold: manual flags plus nightly policy evaluation auto-flagging documents at retention thresholds; legal hold blocks hard delete (423 Locked), version purge, and matter archival; release requires the legal-hold role and a logged reason; hold banner on document and matter-level hold lists. | a.e | planned |
| DOC-29 | Retention enforcement and disposition workflow: flagged documents enter a disposition queue (destroy / extend with new date + reason / archive); destruction requires two distinct approvers (dual control), then blobs+versions are deleted and a permanent tombstone retained; archive moves blobs to cold storage with preview disabled. | a.e, a.d | planned |
| DOC-30 | Append-only document audit trail: every mutation and every sensitive read (view/download/preview of flagged documents, share, restore, destroy) logged with actor, action, document/version, IP, user agent, timestamp; DB-level revoke of UPDATE/DELETE for the app role; per-document Activity tab and matter-level CSV export. | a.c, a.d, a.e | planned |

---

## Section 2 — Legal Calendaring (CAL-01 … CAL-22)

Covers RFI §3b: b.a calendaring + task management · b.b reminders and updates · b.c matter templates with auto-populating discovery and court deadlines.

| ID | Requirement (condensed) | RFI trace | Status |
|---|---|---|---|
| CAL-01 | First-class calendar entity owned by exactly one matter (matter creation auto-creates a default calendar); name, color, owner; unauthorized users cannot read via API (403); list API returns only accessible matters' calendars. | b.a | planned |
| CAL-02 | Calendar event CRUD: title, description, timezone-aware start/end (UTC + originating IANA zone), all-day flag, location, matter/task link; soft delete (tombstone) so audit survives; end ≥ start validated (422). | b.a | planned |
| CAL-03 | Recurrence via one stored RRULE string per series (RFC 5545 subset); server-side occurrence expansion within requested range; edit scopes: this event / this and following / entire series. | b.a | planned |
| CAL-04 | Day view: 00:00–24:00 timeline in the user's timezone, overlap column layout, click-to-create pre-filled with clicked time, all-day strip on top. | b.a | planned |
| CAL-05 | Week view: 7-day grid, configurable Monday/Sunday start, multi-day events span columns, "today" highlighted, prev/today/next navigation. | b.a | planned |
| CAL-06 | Month view: event chips per day (3 shown, "+N more" popover lists the rest), click a day to open day view. | b.a | planned |
| CAL-07 | Agenda view: chronological upcoming list grouped by day with keyword filter and "only deadlines" filter, infinite scroll; the primary "keep users abreast" surface alongside the dashboard. | b.a | planned |
| CAL-08 | Task CRUD on matters: title, description, due datetime, priority, status (todo/in-progress/done), assignee, optional linked calendar event with two-way due-date sync; done→todo requires a comment. | b.a | planned |
| CAL-09 | Ordered subtask checklists under a task (title + done flag); progress shown as done/total; completing all subtasks does not auto-complete the task. | b.a | planned |
| CAL-10 | Task assignment restricted to users with matter access (else 422); assignment and status changes notify assignee and watchers; task list filters by assignee, status, due date, matter. | b.a, b.b | planned |
| CAL-11 | Reminder model on events/tasks: relative offsets (15 min / 1 day / 1 week) or absolute times, per channel; offsets re-resolve when the target's time changes; snoozing creates a new absolute-time reminder. | b.b | planned |
| CAL-12 | Durable notification pipeline: transactional outbox written with the triggering action, background worker delivery via in-app inbox and configured email provider, exponential backoff (5 attempts), per-notification idempotency keys, admin dead-letter view. | b.b | planned |
| CAL-13 | Per-user notification preferences: channels per type (in-app/email/both/none), quiet hours (email held, in-app recorded), daily digest toggle; sane defaults. | b.b | planned |
| CAL-14 | Data-driven deadline rules table: jurisdiction × matter type × trigger event → offset days, calendar/business day basis, description, **mandatory citation**, reminder offsets, effective_from/effective_to versioning; **rules must be verified against primary sources and carry a `verified` flag — unverified rules are excluded from computation**. | b.c | planned |
| CAL-15 | Deterministic deadline computation engine: given jurisdiction/matter type/trigger/date, compute deadlines from verified rules honoring business-day math (weekends + court-holiday table skipped); results become `deadline` records flagged `is_deadline`, surfaced in agenda/deadline views; pure and unit-tested. | b.c | planned |
| CAL-16 | Matter templates: named bundles of trigger watchlist + task/event blueprints with relative timing and role-based (not named-user) default assignees; versioned — edits create new versions, existing matters keep generated items. | b.c | planned |
| CAL-17 | Template application flow: select template → preview of exact items and computed dates → confirm → transactional creation tagged with template id + version; double-apply blocked unless rolled back; rollback deletes only that application's items. | b.c | planned |
| CAL-18 | Trigger-date change → recompute all derived deadlines/events/reminders transactionally (business-day rules re-evaluated, not blindly shifted); audit old→new with actor; notify affected users; partial failure rolls back everything. | b.b, b.c | planned |
| CAL-19 | Reminder runtime: fire once, snooze, dismiss, auto-dismiss on task completion; escalation — if a deadline fires with its task still open, notify the assignee's designated escalator (e.g., supervising attorney, configured per matter). | b.b | planned |
| CAL-20 | Upcoming-deadlines dashboard: matters/user-scoped, 7/30/90-day windows, urgency grouping (overdue → today → this week → later), overdue visually distinct; the default landing view; loads <1 s with 1,000 deadlines in scope. | b.a | planned |
| CAL-21 | Calendaring authorization: matter roles (owner/editor/viewer/outside_counsel) inherited by all calendaring objects; all permission checks server-side; access revocation effective within 60 s. | b.a, b.b | planned |
| CAL-22 | Immutable audit trail for calendaring: every create/update/delete of events, tasks, reminders, triggers, and template applications logged (actor, action, object, before, after); append-only; filterable by object and date range. | b.a, b.b | planned |

---

## Section 3 — Case & Matter Management (MAT-01 … MAT-22)

Covers RFI §3c: c.a lifecycle tracking + retention holds · c.b comments, notes trail, matter status · c.c assign authorized users · c.d calendar coordination with users + outside counsel · c.f matter templates · c.g secure file sharing. (c.e integrations are owned by the Platform section.)

| ID | Requirement (condensed) | RFI trace | Status |
|---|---|---|---|
| MAT-01 | Matter CRUD: required title, auto-generated unique `matter_number` (`YYYY-NNNN`), matter type, description, client name; state starts `INTAKE`; `matter_number`/`created_at` immutable; soft delete recoverable by org admins within 30 days. | c.a, c.b | planned |
| MAT-02 | Lifecycle state machine: `INTAKE → ACTIVE → DISCOVERY → PRE_TRIAL → TRIAL_SETTLEMENT → CLOSED → RETENTION_HOLD → DISPOSITION`; only defined transitions allowed (invalid → 409 with legal next states); every transition audited with actor, from/to, note; concurrent transitions serialized. | c.a, c.b | planned |
| MAT-03 | Parties and contacts per matter: client, opposing party, opposing counsel, witness, expert, court/other — with role description, email, phone, address, optional user link; soft delete; at least one `client` party required before `INTAKE → ACTIVE`. | c.a, c.b | planned |
| MAT-04 | Received/sent document log: every matter attachment logged with direction (received/sent), counterparty, date, method (upload/email/share_link/integration), notes; append-only — core fields immutable, annotations allowed, corrections as new rows. | c.a, c.g | planned |
| MAT-05 | Comments and notes thread: markdown, one-level threading (replies can't nest), author edit within 24 h (edited badge), soft delete renders tombstone; @mentions of assigned users trigger exactly one notification. | c.b | planned |
| MAT-06 | Immutable matter activity/audit feed: every mutating action writes an `audit_events` row in the same transaction; DB-enforced immutability (no update/delete API); human-readable newest-first feed, filterable by type/actor, paginated. | c.b | planned |
| MAT-07 | Per-matter user assignment with roles (owner/editor/viewer/outside_counsel); only owner or org admin assigns; last-owner protection (demoting/removing the final owner → 422); "My Matters" shows only assigned matters for non-admins; unassigned access → 403 (no existence leak beyond forbidden). | c.c | planned |
| MAT-08 | Outside counsel invitation: expiring (72 h) single-use token link, optional folder scoping; external users flagged `is_external`, see only invited matters, cannot enumerate the org user directory or access admin screens; every external action carries the `external` marker in audit. | c.c, c.d | planned |
| MAT-09 | Matter template definitions: org-level versioned templates (checklist items, requested documents, task templates with offsets and assignee roles, deadline rules with anchor expressions); immutable published versions; invalid deadline rule expressions rejected at save with the offending rule named. | c.f | planned |
| MAT-10 | Apply template to a new matter: one call atomically copies checklist, requested-document placeholders, and tasks with anchor-based due dates; missing anchors → "unscheduled" tasks (never wrong dates); records template id + version; partial application rolls back. | c.f, c.d | planned |
| MAT-11 | Per-matter template customization: checklist, tasks, and deadlines fully editable after application without affecting the template or other matters; overridden due dates show original + override; "modified from template" indicator when any deviation exists. | c.f | planned |
| MAT-12 | Matter-level retention holds: block matter/document delete and disposition during hold (409 naming hold expiry); holds extendable, never shortened except by org admin with logged justification; expiry moves matter to `RETENTION_HOLD` via nightly job and notifies owners; holds survive reassignment and user deactivation. | c.a | planned |
| MAT-13 | Internal secure share links: expiring (max 30 days) token URLs for authenticated org users; read/download only, no escalation or share-forwarding; access count per distinct user per day; instantly revocable; all accesses audit-logged. | c.g | planned |
| MAT-14 | External secure share links: unauthenticated 128-bit token credential (stored hashed); options `allow_upload` (lands as `received` with share noted), `require_email`, `max_views` (enforced exactly), optional password (generic 403 on failure); default 7-day expiry (max 30); minimal branded page showing only shared items; full audit. | c.g | planned |
| MAT-15 | Matter list, search, and filters: full-text search across title, matter number, client name, party names, note bodies, plus structured filters (state, type, assignee, client, updated-since, open tasks) combined with AND; permission-scoped before pagination; p95 < 500 ms at 10k matters. | c.a, c.b | planned |
| MAT-16 | Computed matter status summary ("where the matter stands"): lifecycle state + days in state, documents received/sent counts, open/overdue tasks, deadlines next 14 days, unread comments, last activity + actor, active holds/shares, assigned team — derived on read from existing tables (no driftable status table). | c.b | planned |
| MAT-17 | Related matters linking: bidirectional links (same_client/consolidated/appeal/companion/other) with note; symmetric display; self-link rejected (422); related matters the user can't access render as "restricted matter" with no title/metadata leak. | c.a, c.b | planned |
| MAT-18 | Matter tasks and deadlines linkage: matter-scoped Tasks tab grouped by status; deadlines surfaced on the calendar (entity owned by the Calendaring spec); deleting a matter blocked while open tasks exist unless `force=true` with owner role (logged); checklist completion never auto-transitions lifecycle state. | c.b, c.d | planned |
| MAT-19 | Guided closure workflow: pre-close validation (no open tasks or explicit override with reason, external shares auto-revoked with warning, closing note required) → state `CLOSED` + auto-created retention hold from org policy (default 7 years, configurable); closed matters read-only except reopen and hold management. | c.a, c.b | planned |
| MAT-20 | Post-retention disposition: org-admin review queue for matters in `RETENTION_HOLD` with expired holds; requires two distinct org-admin approvals (four-eyes); `archive` → immutable WORM export with hash manifest, then soft-delete; `destroy` → cryptographic deletion with permanent tombstone (matter number, date, method, approvers); blocked during any active hold. | c.a | planned |
| MAT-21 | Matter notifications: assignment/unassignment, @mentions, state transitions, task assigned/overdue, document shares, invite accepted/expired, retention-hold expiry warnings (30/7/1 day), optional per-share access notices; per-matter user preferences (all/mentions-only/none); in-app + email honoring global unsubscribe. | c.b, c.c, c.d | planned |
| MAT-22 | Centralized permission enforcement: every matter-scoped endpoint calls `require_matter_access(user, matter_id, minimum_role)` before any data access; org admin → allow, sufficient assignment → allow, valid share token → scoped allow, else 403; automated test matrix covering every endpoint × every role; no grant cache older than 60 s. | c.b, c.c, c.g | planned |

---

## Section 4 — Platform, Security & Integrations (PLT-01 … PLT-35)

Cross-cutting foundation: identity & access (PLT-01–PLT-12), audit (PLT-13–PLT-14), notifications (PLT-15–PLT-18), security fundamentals (PLT-19–PLT-23), Phase 1 integrations (PLT-24–PLT-27), Phase 2 integrations (PLT-28–PLT-31, specified now, built later), messaging (PLT-32–PLT-35). Covers RFI §3c.e (integrations), plus c.b, c.g, and AI/security questions.

| ID | Requirement (condensed) | RFI trace | Status |
|---|---|---|---|
| PLT-01 | Self-registration with verified email: password policy (min 12 chars, breached-password check via k-anonymity or local blocklist), `pending_verification` state, single-use 24 h verification token; no user-enumeration leaks (identical responses/timing, generic duplicate-email message). | c.b, c.g | planned |
| PLT-02 | Email + password login with Argon2id hashes (memory ≥ 64 MB, iterations ≥ 3) via constant-time comparison; per-account and per-IP brute-force counting (5 failures → 15-min lockout, exponential backoff, lockout events logged); successful login rotates session and clears counters. | c.b | planned |
| PLT-03 | Self-service password reset via 256-bit single-use token (hash stored, 1 h expiry) emailed as a link; never emails the password; revokes all user sessions on success; rate-limited per email and IP; generic responses for nonexistent emails. | foundational | planned |
| PLT-04 | Email invitations (256-bit token hash, 7-day expiry) into org/team/matter with role + scope; link is email-bound (rejected for a different signed-in user); drives registration for new users and grant-add for existing; revocable, admin-listable, nightly purge of expired. | c.b, c.d | planned |
| PLT-05 | TOTP MFA (optional-but-encouraged; required for org admins): QR/manual enrollment confirmed by a valid code before activation; 10 single-use hashed backup codes; disabling requires password re-authentication; admin reset is logged and emailed. | Q5/Q9 | planned |
| PLT-06 | SAML 2.0 SSO (SP-initiated) against customer IdP: per-org metadata config, attribute mapping, JIT provisioning with IdP-group→role mapping, optional or enforced per org (enforced disables password login for that org's domain); full response validation (signature, audience, timestamps, replay cache). | Q9 | planned |
| PLT-07 | OAuth2 login (Google/Microsoft) with PKCE authorization-code flow; ID token signature/issuer/audience verification; SSO-enforced domains redirect to SAML instead of bypassing. | c.c | planned |
| PLT-08 | Session management: short-lived access tokens (15 min) + rotating refresh tokens (30 days, single-use rotation with reuse detection → whole-family revocation + security email); user-visible session list with per-session revoke; admin revoke-all; 12 h absolute lifetime for privileged roles; configurable concurrent-session limit (default 5). | foundational | planned |
| PLT-09 | Organizations as the top tenant boundary: every domain row carries `org_id`; org scoping enforced in a shared data-access layer, not per endpoint; cross-org access returns 404 (no existence leak); automated cross-org test coverage on every major resource. | c.b, Q9 | planned |
| PLT-10 | Teams: named groups inside an org for bulk permission assignment; matter grants can target teams; membership changes propagate to effective permissions within 60 s (computed at request time). | c.b, c.c, c.d | planned |
| PLT-11 | RBAC with five fixed roles — `org_admin`, `attorney`, `paralegal`, `outside_counsel`, `viewer` — and a single-source-of-truth permission matrix file (e.g., YAML) loaded at startup; central server-side `authorize(user, action, object)` on every request; table-driven matrix tests; `outside_counsel` cannot enumerate org users. | c.b, c.d | planned |
| PLT-12 | Per-matter grants (user or team, role override, optional expiry); effective permission = most permissive of org default, team grants, direct grants, except `outside_counsel` which is default-deny without explicit grants; changes immediate and audit-logged; expired grants deny automatically. | c.b, c.c, c.d | planned |
| PLT-13 | Append-only audit log: single writer inserts rows (actor, org, action, object type + ID, before/after diff, IP, user agent, timestamp) on every create/update/delete/grant/login/permission change; DB-level REVOKE of UPDATE/DELETE for the app role; hash-chained rows with nightly integrity check. | c.b, Q9 | planned |
| PLT-14 | Admin audit log viewer: org admins filter by actor, object type, action, date range, free text; paginated; CSV/PDF export watermarked with exporter identity and timestamp; non-admins get 403. | c.b | planned |
| PLT-15 | In-app notification center: domain events (assignment, share, mention, approaching deadline, invite events) fanned out per recipient to a notifications table; unread badge, deep links, mark-all-read; no notification leaks object content the recipient can't access. | b.b, c.b | planned |
| PLT-16 | Transactional email pipeline: single mailer service, configurable provider (SES/Postmark/Mailgun), versioned templates with plain-text fallback, per-email idempotency keys, bounce/complaint webhook suppression, queue-backed with dead-letter visibility. | foundational | planned |
| PLT-17 | Notification preferences: per-user per-org control of event types × channels (in-app/email/both/none); "mute this matter" suppresses non-mention notifications for that matter; changes immediate. | b.b | planned |
| PLT-18 | Digest emails: daily/weekly aggregation of unread actionable items (deadlines, mentions, assigned tasks) at the user's cadence and local timezone (DST-correct); @mentions and security events still send immediately; per-email-type unsubscribe via signed token without login. | b.b | planned |
| PLT-19 | Encryption in transit: TLS 1.2+ on all public endpoints with HSTS (max-age ≥ 1 year, preload-ready); TLS on internal service traffic; ACME certificate automation with 30/7/1-day expiry alerting; SSL Labs-style scan A or better; no mixed content. | a.c, a.d, c.g, Q9 | planned |
| PLT-20 | Encryption at rest: provider-managed DB encryption (or LUKS self-hosted) with customer-data key separation where supported; SSE on object storage with per-org key prefixing; envelope encryption (per-org DEK, KMS KEK) for the most sensitive fields (identifiers, OAuth refresh tokens, backup codes); annual rotation runbook. | a.c, a.d, c.g, Q9 | planned |
| PLT-21 | Secrets management: no secrets in code, config, logs, or transcripts — all secrets in a secrets manager (KMS/Secrets Manager/Vault) fetched at boot with IAM-scoped access; rotation without redeploy where supported; OIDC-based CI with no long-lived secrets; pre-commit + CI secret scan; log scrubber for token-like patterns. | Q9, AI boundary | planned |
| PLT-22 | Backups and disaster recovery: daily full + continuous WAL/PITR for Postgres (RPO ≤ 1 hour); versioned object storage with cross-region replication; quarterly restore drill to an isolated environment with automated smoke tests; RTO ≤ 4 hours; checksum-verified backups with failure alerting within 15 min. | a.d, Q9 | planned |
| PLT-23 | File transfer hardening: presigned-URL direct-to-storage uploads (never through app memory for large files), MIME sniffing, per-file size caps, synchronous AV before visibility, quarantine bucket; short-lived (≤ 15 min) signed download URLs with permission re-checked at download time; all transfers audit-logged. | a.d, c.g | planned |
| PLT-24 | Microsoft Graph OAuth: app registration supporting single-tenant and multi-tenant (admin-consent flow); delegated least-privilege scopes (`Files.ReadWrite`, `Mail.Send`, `User.Read`); per-user encrypted token storage with silent refresh; connection status UI with disconnect (deletes tokens). | c.e | planned |
| PLT-25 | OneDrive/SharePoint file pull and push: Graph drive file picker imports a copy into a matter (source recorded, audit-logged); "save back" pushes the current version; remote-change conflict dialog (keep mine / keep theirs / keep both); manual pull/push only — no delta sync. | c.e, a.d | planned |
| PLT-26 | Export to Word (.docx) and PDF: .docx via document library with headings mapped to styles; PDF via headless Chromium print pipeline with branded header/footer, page numbers, matter metadata (text-selectable); async generation with progress for large documents; every export audit-logged and permission-gated. | c.e, a.a | planned |
| PLT-27 | Send email via Outlook/Graph: in-app compose (to/cc/bcc, subject, body, matter-document attachments or sharing links per org policy); sends via Graph from the connected user's identity (impersonation impossible); sent mail logged to the matter timeline (metadata; body per retention policy); save-to-Outlook-drafts option. | c.e | planned |
| PLT-28 [P2] | Teams messaging and bot (Phase 2): Teams app package with bot + message extension; matter↔channel linking with configurable event posts (adaptive cards); `@app` commands (show deadlines, summarize matter, find document); Entra SSO. | c.e | planned (Phase 2) |
| PLT-29 [P2] | Word add-in (Phase 2): Office.js task-pane add-in — browse matter documents, insert clauses/templates, push the open document back as a new matter version; manifest hosted by us; SSO via Office identity. | c.e | planned (Phase 2) |
| PLT-30 [P2] | Adobe PDF Services (Phase 2): API-key-authenticated combine/split/OCR/compress/PDF→Word; results land in the matter document store; every operation audit-logged with page counts for cost tracking. | c.e | planned (Phase 2) |
| PLT-31 [P2] | Bluebeam Cloud API (Phase 2): OAuth push of a matter PDF to a Studio session, poll for markup completion, pull the marked-up PDF back as a new version (flattened or layered per user choice); session links recorded on the matter timeline. | c.e | planned (Phase 2) |
| PLT-32 | Matter-scoped message threads: persistent chat threads on matters (optionally documents) with attachments, markdown, soft delete with tombstones; real-time via WebSocket/SSE, offline-tolerant client queue; threads inherit matter permissions; full-text searchable within the matter. | c.b | planned |
| PLT-33 | @mentions: autocomplete scoped to users/teams with matter access; mention creates notification (+ email per preferences); team mention notifies all members; `@all` requires attorney+ role. | c.b | planned |
| PLT-34 | Read receipts: per-message per-user read tracking written on view (debounced) or thread open; "Seen by X, Y" UI; receipt data permission-scoped. | c.b | planned |
| PLT-35 | Email fallback for messaging: unread mention (or per-preference direct message) emailed at T+30 min with VERP-style reply-to; email replies ingested and posted to the thread as the user (attachments supported); loop protection (max 3/day/thread/user); spoofed replies rejected and logged. | c.b, b.b | planned |

---

## Item counts

| Section | IDs | Count |
|---|---|---|
| 1 — Document Management | DOC-01 … DOC-30 | 30 |
| 2 — Legal Calendaring | CAL-01 … CAL-22 | 22 |
| 3 — Case & Matter Management | MAT-01 … MAT-22 | 22 |
| 4 — Platform, Security & Integrations | PLT-01 … PLT-35 | 35 |
| **Total** | | **109** |

## RFI coverage index

| RFI item | Matrix items | Notes |
|---|---|---|
| 3a.a create/edit/access documents + templates | DOC-01–DOC-10, DOC-22–DOC-23 | |
| 3a.b collaborate with authorized users | DOC-24–DOC-25, DOC-13–DOC-14, PLT-11–PLT-12 | |
| 3a.c access from anywhere, secure use | PLT-01–PLT-08, PLT-19–PLT-21, DOC-05–DOC-07 | |
| 3a.d secure storage, file transfer, maintenance | DOC-01–DOC-03, DOC-08, DOC-11, DOC-26, PLT-20, PLT-22–PLT-23 | |
| 3a.e retention policy flagging | DOC-27–DOC-29 | flagging + enforcement |
| 3a.f version control, search, rollback | DOC-14–DOC-21 | |
| 3b.a calendaring + task management | CAL-01–CAL-10, CAL-20–CAL-21 | |
| 3b.b reminders and updates | CAL-11–CAL-13, CAL-19, PLT-15–PLT-18 | |
| 3b.c matter templates + auto-populating deadlines | CAL-14–CAL-18 | rules verified against primary sources |
| 3c.a lifecycle tracking + retention holds | MAT-01–MAT-04, MAT-12, MAT-19–MAT-20 | |
| 3c.b comments, notes trail, matter status | MAT-05–MAT-06, MAT-15–MAT-17, PLT-13–PLT-14, PLT-32–PLT-35 | |
| 3c.c assign authorized users | MAT-07, PLT-11–PLT-12 | |
| 3c.d coordinate calendars with users + outside counsel | MAT-08, MAT-21, CAL-01, PLT-04, PLT-07 | |
| 3c.e MS/Adobe/Bluebeam integrations | PLT-24–PLT-31 | PLT-24–PLT-27 Phase 1 thin-real; PLT-28–PLT-31 Phase 2 |
| 3c.f templates for common litigation matters | MAT-09–MAT-11, CAL-16–CAL-17 | |
| 3c.g secure file sharing | MAT-13–MAT-14, DOC-25–DOC-26, PLT-23 | |

## Extraction notes

- All 109 items extracted cleanly from the four build specs. No item required invention: each row condenses its spec line item's description, behavior, and acceptance criteria.
- GovRAMP (RFI Q9) is intentionally **not** a build item — it is a certification process, addressed as a roadmap note in `ROADMAP.md`.
- Items tagged [P2] in the source spec (PLT-28–PLT-31) are specified now but built in Phase 2; they remain `planned` with the Phase 2 annotation.
- c.e touchpoints named in the Matter spec (share-to-Teams event, export matter binder, open-in-Bluebeam handoff) are covered by PLT-28/PLT-31 and MAT-21; no separate MAT-23 item exists in the source specs.
