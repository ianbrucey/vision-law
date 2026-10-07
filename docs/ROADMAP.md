# Vision Law — Phased Roadmap

**Status:** DRAFT — pending product-owner approval
**Owner:** Justice Quest LLC (Ian Bruce)
**Last updated:** 2026-10-06

Derived from the 8-phase dependency-first build sequence and the Georgia DOT RFI timeline (**eRFI-002022-2027 closes October 28, 2026**). The build order below is the dependency order; calendar dates are planning targets, not commitments — the RFI response must never present roadmap items as delivered functionality.

---

## 1. Timeline context

| Date | Milestone |
|---|---|
| Oct 6, 2026 (today) | Repo scaffolded; system docs being written; build protocol approved for use |
| ~Oct 11, 2026 | Core sprint target: demoable vertical slice end to end |
| Oct 12 → Oct 28, 2026 | RFI readiness: deadline rules (verified), matter templates, retention flagging, secure sharing, thin integrations, the RFI response itself |
| **Oct 28, 2026** | **RFI closes** — submit early; submission is not instantaneous (allow a full day) |
| Post-Oct 28 | Phase 2: deep integrations, retention enforcement hardening, GovRAMP readiness track, more jurisdictions |

**Response strategy reminder:** claim "yes" only where acceptance criteria are met and demoable; everything else is an honest "partially" with a dated delivery path. GovRAMP is a hard "no — roadmap" (a certification process, not a feature).

---

## 2. Delivery strategy: the thin vertical slice

**Ship a thin vertical slice before any subsystem is "complete."** The first credible demonstration crosses the entire architecture while keeping each capability intentionally narrow:

> **open matter** (authorized team + parties) → **upload** (scan + OCR + version + search) → **deadline** (one verified rule chain) → **share** (one scoped guest)

- **Demonstrate the system, not isolated modules.** A polished single-module demo that can't survive authorization, audit, or recovery testing is not progress.
- **Release gate:** the slice is ready only when the same user story passes functional tests, authorization tests, audit review, and a recovery test. A polished interface does not substitute for those proofs.
- **Do not fake:** no "immutable" audit without DB controls; no production upload story without real object storage; no unverified legal calculations; no broad link access; no "integrates with" claims for unbuilt connectors.

---

## 3. Parallel tracks (start day one, run alongside the phases)

Some work has lead time that cannot be compressed. These tracks start immediately even though their implementation lands in later phases:

| Track | Starts | First proof | Do not fake |
|---|---|---|---|
| **A — Architecture contracts** | Immediately | Authorization decision contract; domain events + audit schema; document/version identity; rule provenance + override model; RFI requirement traceability | Parallel ownership models |
| **B — Verification & evidence** | Immediately | Legal rule sources + reviewer identified; integration feasibility spikes (Graph/Adobe/Bluebeam: API access, licensing, tenant consent, rate limits, sandbox availability); security control/evidence register | Certification claims before approval |
| **Deadline-rule legal research** | Day one | One verified rule chain (GA state civil + federal civil proposed) with primary-source citations | Unverified legal calculations |
| **Storage** | Day one | Reliable S3-compatible object storage selected and working (known enabling gap) | Production uploads on local disk presented as production-ready |

---

## 4. The eight build phases

### Phase 1 — Identity, matter-level RBAC, audit

**Goal:** know who may act, and prove what happened. Security is not a wrapper added after features — it determines data shape, API boundaries, test fixtures, and evaluation evidence.

**Build:** SSO- and MFA-ready identity (PLT-01–PLT-08); RBAC at organization and matter scope with a single-source-of-truth permission matrix (PLT-09–PLT-12); tamper-evident hash-chained audit events for reads, writes, exports, shares, and admin changes (PLT-13–PLT-14); notification and email pipeline foundations (PLT-15–PLT-18); encryption, secrets, backup controls (PLT-19–PLT-23).

**Exit criteria (exit evidence):**
- An authorization test matrix proves allowed and denied actions across roles, including cross-tenant denial without existence leakage (404-not-403).
- Audit records are queryable, exportable by org admins, and cannot be edited or deleted through the application (DB-enforced).
- A permission change takes effect within 60 seconds; revoked sessions die on next API call.

**RFI relevance:** the control plane behind every 3a/3b/3c claim; Q9-adjacent evidence.

---

### Phase 2 — Matter model and lifecycle

**Goal:** the single place where legal work lives. Documents, deadlines, permissions, retention, and external collaborators all attach to a matter — this model prevents later features from inventing competing ownership structures.

**Build:** matter CRUD with stable identifiers (MAT-01); lifecycle state machine with guarded transitions (MAT-02); parties and contacts (MAT-03); received/sent document log (MAT-04); comments thread (MAT-05); immutable activity feed (MAT-06); per-matter assignment and roles (MAT-07); matter list/search (MAT-15); status summary (MAT-16); related matters (MAT-17).

**Exit criteria:**
- A user can open a matter, assign a team, add parties, and see a complete authorized activity history.
- Every lifecycle transition is validated against the transition table and audited; invalid transitions return the legal next states.
- Non-assigned users cannot discover the matter (403 without existence leak beyond forbidden).

---

### Phase 3 — Document management core

**Goal:** convert document ingestion into governed legal work product — broad visible RFI coverage with comparatively low greenfield risk.

**Build:** production object storage + malware-safe upload path (DOC-01–DOC-03); validation and metadata extraction (DOC-04); PDF/Office/image previews (DOC-05–DOC-07); download with integrity headers (DOC-08); built-in editor and template library + generation (DOC-09, DOC-22–DOC-23); matter filing, folders, metadata search (DOC-12–DOC-14); text extraction + OCR + full-text search (DOC-15–DOC-17); immutable versioning, history, rollback, diff (DOC-18–DOC-21); document audit trail (DOC-30).

**Exit criteria:**
- Upload a document to a matter, process it (scan → extract/OCR), find it (search), revise it (new version), restore a prior version — and prove the chain of events in the audit trail.
- Identical bytes dedupe to one blob; byte-identical re-upload is a versioning no-op.
- Permission-denied users cannot preview, download, or search-hit the document.

**RFI relevance:** §3a (a.a–a.f) — the largest single RFI section.

---

### Phase 4 — Calendaring and deadline rules engine

**Goal:** precision before convenience. Deadline automation is the highest-risk domain build: wrong dates are malpractice-adjacent, so rules are data with citations, verified before they compute.

**Build:** matter calendars and all four views (CAL-01–CAL-07); tasks, subtasks, assignment workflow (CAL-08–CAL-10); reminders + durable notification pipeline + preferences (CAL-11–CAL-13, CAL-19); deadline rules table with mandatory citations and verified flags (CAL-14); deterministic computation engine with business-day/holiday math (CAL-15); matter templates and application flow (CAL-16–CAL-17); recalculation on date changes (CAL-18); upcoming-deadlines dashboard (CAL-20); calendaring authorization and audit (CAL-21–CAL-22).

**Why fourth:** deadlines require stable matters and auditable events. Implementation follows the foundation, but **rule research and attorney verification begin in Phase 1** — legal accuracy cannot be compressed.

**Exit criteria:**
- A verified trigger produces traceable deadlines (rule → citation → computed date).
- Moving a trigger date recalculates all dependents transactionally (business-day rules re-evaluated); the change is audited and affected users are notified.
- Every manual override records who, when, and why.

**RFI relevance:** §3b (b.a–b.c). Target: b.c at "Partially+" for GA state + federal common matter types, rules verified against primary sources.

---

### Phase 5 — Retention, legal holds, and disposition

**Goal:** a governed information lifecycle. Disposition is destructive — it must not exist until document identity, matter ownership, permissions, versioning, and audit controls are already reliable.

**Build:** retention policy definitions + simulator (DOC-27); retention flagging and legal holds with matter and document scope (DOC-28, MAT-12); disposition review queue with dual-control approvals (DOC-29); matter closure workflow with auto retention hold (MAT-19); post-retention disposition with four-eyes approval, WORM archive, and cryptographic destruction with tombstones (MAT-20).

**Exit criteria:**
- Held content cannot be disposed (409/423), even by org admins without proper hold release.
- Eligible content enters the review queue; the approved action leaves a complete record (approvals, manifest, tombstone).
- Expired holds transition matters to `RETENTION_HOLD` via the nightly job with owner notification.

**RFI relevance:** §3a.e, §3c.a ("for the duration of retention holds").

---

### Phase 6 — Outside counsel and external sharing

**Goal:** the most sensitive authorization edge. External access amplifies every authorization error — build it only after internal matter boundaries are proven and audit coverage is complete.

**Build:** outside-counsel invitations with folder scoping and `external` audit marking (MAT-08); internal share links (MAT-13); external share links with upload-back/password/view-limits (MAT-14, DOC-25); encrypted signed-manifest transfer packages (DOC-26); matter-scoped messaging threads, @mentions, read receipts, email fallback (PLT-32–PLT-35).

**Exit criteria:**
- A guest sees only invited matter content; access expires or revokes immediately and in-flight sessions die.
- An external recipient cannot enumerate other documents or users.
- All external actions are attributable in the audit trail with the `external` marker.

**RFI relevance:** §3c.d, §3c.g, §3a.b.

---

### Phase 7 — Integrations (thin-real first)

**Goal:** reach the ecosystem without making it the foundation. Integrations connect stable Vision Law objects and permissions — discovery starts early, but production implementation follows the core so external systems never dictate the domain model.

**Wave 1 — evaluator-visible, thin but real (RFI readiness):** Microsoft Graph OAuth (PLT-24); OneDrive/SharePoint pull/push (PLT-25); Word/PDF export (PLT-26); send-via-Outlook (PLT-27).

**Wave 2 — deeper workflow fit (post-RFI):** Teams bot (PLT-28); Word add-in (PLT-29); Adobe PDF Services depth (PLT-30); Bluebeam markup round-trip (PLT-31).

**Exit criteria:**
- A user moves a controlled document through at least one Phase-1 integration without losing version, permission, matter, or audit context.
- No integration is claimed beyond what actually works (thin-real, never vapor).

**RFI relevance:** §3c.e. Target posture: Phase 1 thin-real = "Partially"; Wave 2 = dated roadmap.

---

### Phase 8 — Governed AI assistance

**Goal:** differentiator, not dependency. AI becomes safer and more useful once matters, documents, permissions, and provenance are stable. Existing semantic search is retained and governed early; expanded generative workflows do not block the core.

**Build:** grounded matter summarization with citations; drafting assistance in controlled working copies (see `DRAFTING_ARCHITECTURE.md`); semantic retrieval with permission filtering (pgvector); human approval gates; prompt/output audit; model policy; non-AI fallback for every AI-assisted workflow.

**Exit criteria:**
- Every answer is permission-scoped; sources are visible; edits require human acceptance.
- The same core workflow remains usable without AI.
- AI evaluations on fixed synthetic matters assert tool choice, allowed citations, provenance, schema validity, and abstention — never exact prose.

**RFI relevance:** Questions 5–7 (AI disclosure).

---

## 5. RFI-readiness mapping (Oct 12 → Oct 28)

What the response needs, and which phase supplies it:

| RFI need | Source phase | Target posture |
|---|---|---|
| §3a document management demo (upload → preview → version → search) | Phase 3 | Yes |
| §3b calendaring + tasks + reminders | Phase 4 (core) | Yes |
| §3b.c deadline rules for GA state + federal common matter types, verified | Phase 4 + Track B | Partially+ (dated path to more jurisdictions) |
| §3c matter lifecycle, comments, assignment, templates, sharing | Phases 2 + 6 | Yes; outside-counsel coordination Partially+ |
| §3c.e thin integrations (Graph OAuth, file pull/push, export) | Phase 7 Wave 1 | Partially (thin-real) |
| Retention flagging; enforcement | Phase 5 | Yes (flagging); Partially+ (enforcement) |
| Yes/no/partially matrix + narratives + 11 answers + pricing/TCO + implementation plan + 5-year roadmap | — | Written from this roadmap + matrix |
| Demo dry-run | Thin slice | Matter → upload → deadline → share |

---

## 6. Post-RFI / Phase 2 (RFP readiness)

- **Deep integrations:** Teams bot, Word add-in, Adobe PDF Services depth, Bluebeam markup round-trip (PLT-28–PLT-31).
- **Retention enforcement hardening:** dual-control disposition at scale, WORM archive operations.
- **GovRAMP readiness track:** controls evidence collection against the NIST 800-53-aligned baseline (PLT-13/PLT-19–PLT-23 are the foundation); readiness assessment after first state-customer traction; certification itself is a separate 6–12+ month process — never scoped as a sprint.
- **More jurisdictions** for the deadline rules engine, with the same verify-before-ship rule.
- **Deferred from v1:** e-signature, real-time co-editing, track-changes/redlining in the editor, email ingestion, SCIM provisioning, WebAuthn/passkeys, customer-managed encryption keys, data-residency pinning, native mobile apps, client portal.

---

## 7. Decisions to settle before sizing (not after)

1. What the RFI response is promising — separate working, demonstrable, planned, partner-provided, and certification-dependent capabilities.
2. Which jurisdiction and rule set anchors the deadline engine — first rules catalog, authoritative sources, attorney reviewer, update process, disclaimer posture.
3. The external collaboration boundary — guest accounts vs. expiring links vs. client portal vs. controlled file exchange only.
4. What integration depth counts as sufficient — defined separately per product (Outlook, Word, SharePoint, Teams, Adobe Acrobat, Bluebeam): import/export, links, notifications, add-ins, coauthoring, or bidirectional sync.
5. What compliance claim is supportable — GovRAMP readiness vs. active authorization vs. authorized are different states; build the control and evidence program alongside the product.

---

## 8. Source map

- Requirement detail per phase: `docs/RFI_REQUIREMENTS_MATRIX.md`
- Product definition and decision tags: `docs/PRODUCT_BLUEPRINT.md`
- Drafting mechanics (Phase 8 detail): `docs/DRAFTING_ARCHITECTURE.md`
- Build discipline: `docs/Planning_Protocol.md`, `docs/Build_Protocol.md`
