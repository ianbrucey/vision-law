# Vision Law — Drafting Architecture

**Status:** DRAFT — must be approved before any drafting tickets are written
**Owner:** Justice Quest LLC (Ian Bruce)
**Last updated:** 2026-10-06

This document defines the mechanics of agentic legal drafting in Vision Law. Per the build protocol, **drafting work is held until this document is approved** — it is the stop gate for Phase 8 work. Decisions are tagged [D] decided / [P] proposed / [O] open.

**Non-negotiable (from the product blueprint):** [D] drafts are developed on branches in the organization's git repository; **merge = approval**; history = audit trail. Approved versions are immutable. Rendering never alters content.

---

## 1. The structured draft document model

A draft is not a blob of text. It is an ordered list of **addressable, typed sections** [D]. Every section has a stable ID, a type, a typed content payload, an order index, and its own version history — so humans and agents can edit, review, and approve at section granularity.

### Section type taxonomy [D]

| Type | Content payload | Example |
|---|---|---|
| `caption_block` | court name, case title, case number, division, judge assignment | Superior Court of Fulton County caption |
| `heading` | level, text | "ARGUMENT" |
| `numbered_paragraph` | number (auto or pinned), text, optional sub-paragraphs | Paragraph 14 of a complaint |
| `table` | rows/columns of typed cells | Damages schedule |
| `signature_block` | signatory name, title, firm, bar number, date, signature image ref | "/s/ Jane Attorney" |
| `certificate_of_service` | method, recipients, date | Certificate of service |
| `exhibit` | exhibit letter/number, title, reference to a matter document + version | Exhibit A — the contract |
| `clause` | clause ID from the clause library, parameters, rendered text | Indemnification clause v3 |

Typed sections give us three things a flat document cannot: (a) **section-level approval** (approve the caption and paragraphs 1–12 while paragraph 13 is still being revised); (b) **structural validation** (a pleading cannot be approved without a caption block and signature block); (c) **surgical agent edits** (the agent revises exactly one section, with a diff, instead of rewriting the whole draft).

### Canonical representation [O]

**[O] The exact serialization format of the canonical draft is undecided.** Candidates:

1. **Structured sections in the database** — sections as rows (`draft_sections`), rendered on demand; git stores snapshots/exports, not the live draft.
2. **A document format in the organization git repository** — the repo file (e.g., a defined Markdown/JSON schema) *is* the draft; the database caches derived state.
3. **Hybrid with an explicit authority rule** — e.g., the database owns in-progress sections; the repo owns approved versions; a defined promotion step reconciles them.

Git history cannot be treated simultaneously as the authoritative content store *and* merely an approval log without defining reconciliation — whichever option is chosen must state the authority rule explicitly. **Work that depends on this decision stops or defers it.** Everything below is written to survive any of the three options.

---

## 2. The drafting flow

```
template → draft instance → agent fills sections (matter-document retrieval)
  → HTML preview → section-level human edit OR agent revise
  → approve (exact version) → commit to org git repo with filed/sent tags
```

1. **Template.** The user chooses a versioned template (pleading, discovery request, contract, correspondence). Templates declare required section types, merge fields, and the clause library they may draw from. Template version is pinned at draft creation — a later template update never mutates an in-flight draft [D].
2. **Draft instance.** Created on a new branch in the org's git repository (`drafts/<matter-number>/<slug>`), owned by the matter, visible only to matter-authorized users.
3. **Agent fills sections.** The drafting agent works through the AI gateway with narrowly defined tools (read matter documents, retrieve cited passages, propose section text). It fills sections one at a time, each proposal carrying **provenance**: every fact or quotation links to a source document and location [D].
4. **HTML preview.** The renderer produces a paginated preview (pleading line numbers, caption formatting) without altering content.
5. **Human edit or agent revise.** The reviewer selects a section and either edits it directly or asks the agent to revise it ("tighten paragraph 13; cite the contract's §4.2"). Agent revisions arrive as per-section diffs the human accepts or rejects.
6. **Approve.** Approval records **who approved which exact version** (content hash). Post-approval, the version is immutable — any further change creates a new version requiring re-approval [D].
7. **Commit to the org repo.** The approved version is merged to the matter's main line and tagged (`filed` / `sent` / `released` with date). The merge commit *is* the audit event: who approved, what hash, when.

---

## 3. The git working-copy pattern [D]

| Concept | Meaning |
|---|---|
| One repository per organization | Drafts, templates, and released documents for all of the org's matters live in one repo; matter directories scope visibility (enforced by the app, not by git itself). |
| Branch per draft | `drafts/<matter-number>/<slug>`; the agent and the human collaborate on the branch. |
| Merge = approval | A draft is approved if and only if its branch is merged. No merge without a recorded approval event naming the approver and the exact content hash. |
| History = audit trail | `git log` on the matter path shows every revision, author (human or agent identity), and approval. |
| Tags = lifecycle | `filed/<matter>-<date>`, `sent/<matter>-<date>`, `released/<doc>-v<n>` mark externally visible milestones. |
| Conflicts | If two writers change the same section, the second merge conflicts at the section level and must be resolved explicitly — never silently auto-merged. [O] The exact conflict-resolution UX is open (see §8). |

The repository adapter defines branch, commit, merge, tag, and conflict semantics, and is the only code that touches git [P].

---

## 4. Required service boundaries

| Service | Responsibility |
|---|---|
| **Draft service** | create, revise, compare, approve, reject, restore versions. UI code never mutates draft persistence directly — all changes pass through this service [D]. |
| **Template service** | template schema, variables, clause library, validation, version pinning. |
| **Renderer** | canonical draft → HTML preview, PDF, DOCX. Rendering must not alter content [D]. |
| **Citation / provenance service** | every fact or quotation in agent-drafted text links to a source document + location; citations are preserved through HTML, PDF, and DOCX export. |
| **AI drafting gateway** | exposes narrowly defined tools (read section, propose section revision, retrieve matter passages) — never unrestricted filesystem or database access. Privileged tools require recorded human approval [D]. |
| **Approval service** | records who approved which exact version (content hash); enforces post-approval immutability. |
| **Repository adapter** | the only code that touches git: branch/commit/merge/tag/conflict semantics [P]. |

**Architecture laws (enforced by tests, not prose):** controllers and Livewire components never call storage directly (all file operations go through `DocumentStore`); UI code never mutates draft persistence except through the draft service; model providers are called only through the AI gateway.

---

## 5. Rendering pipeline [P]

```
structured draft → HTML (paginated, styled) → PDF via headless Chromium → DOCX export
```

- The HTML rendition is the single source for both PDF and screen preview — one layout code path, no divergent formatting.
- PDF via headless Chromium print pipeline: pleading line numbers, caption blocks, page numbers, headers/footers, matter metadata. Output is text-selectable, never image-only.
- DOCX export maps structure to Word styles (headings → Heading styles, numbered paragraphs → numbered lists), not bold-text fakery.
- Every export is permission-gated and audit-logged.
- [P] Exact renderer implementation (internal service vs. queued job workers, caching of renditions per content hash) is proposed, not yet decided.

---

## 6. Section-based editing UX

- The draft opens as a structured outline: each section is a card showing its type, status (`draft` / `agent-proposed` / `human-edited` / `approved`), and a diff view against the previous version.
- **Human edit:** click a section → edit its typed fields (not raw markup) → save creates a section revision.
- **Agent revise:** click a section → "Ask agent to revise" with an instruction → the agent proposes a new section version with provenance links → the human accepts or rejects the per-section diff.
- Structural validation runs continuously: missing required section types (caption, signature) block approval with the gaps named.
- Approval is per-draft but recorded per-section: the approval event lists every section ID and its content hash, so a later dispute can pinpoint exactly what was approved.
- Real-time co-editing (OT/CRDT) is **out of scope for v1** [D]; the safety net is section-level versioning plus explicit conflict resolution.

---

## 7. Provenance, citations, and AI evaluations

- Every agent-drafted factual claim or quotation carries a citation to a source document **and location** (document ID + version + page/section). Sections without resolvable provenance are marked `uncited` and block approval until a human either supplies citations or accepts the text as human-authored [D].
- Citations survive export: footnotes/endnotes in PDF and DOCX link back to the source document reference.
- AI evaluations use fixed synthetic matters and assert tool choice, allowed citations, provenance completeness, schema validity, abstention behavior, and approval gating — **never exact prose**.
- AI output never substitutes for deterministic authorization, deadline math, retention logic, or audit entries.

---

## 8. Open and proposed items

| # | Item | Tag |
|---|---|---|
| O-D1 | **Exact draft serialization format** — structured DB sections vs. repo-file-as-source-of-truth vs. hybrid with an explicit authority rule (§1). Blocks: draft service schema, repository adapter contract. | [O] |
| O-D2 | **Merge / coauthoring UX** — how concurrent human edits to the same section reconcile; what the conflict-resolution surface looks like; whether section-level locking, last-write-wins-with-history, or OT/CRDT is adopted (v1 default: section versioning + explicit conflict resolution). | [O] |
| O-D3 | **Where the drafting agent loop lives** — Laravel 13 first-party AI SDK agent loop vs. a separate Python worker service called by Laravel. The drafting *intelligence* is Laravel AI SDK [D]; the *loop's* runtime home is undecided and affects deployment, observability, and failure handling. | [O] |
| O-D4 | **AI provider choice** — which model provider(s) back the AI gateway; cost/latency/accuracy and data-boundary tradeoffs (third-party API vs. self-hosted). Affects the OCR-provider decision as well. | [O] |
| P-D1 | Rendering pipeline implementation (service vs. queued workers, rendition caching per content hash). | [P] |
| P-D2 | Repository adapter interface (branch/commit/merge/tag/conflict method signatures). | [P] |
| P-D3 | Whether the Python OCR/chunking pipeline remains a worker service behind Laravel interfaces, or is reimplemented. | [P] |

**Failure modes that must be specified before build:** model unavailable (drafting degrades to manual section editing — the draft remains fully usable); renderer unavailable (preview/export queue with visible retry state, no silent content substitution); repository unavailable (draft service refuses mutation with a clear error rather than diverging from git); search/retrieval unavailable (agent tools report abstention, never hallucinated citations).

---

## 9. Approval gate

No drafting feature brief may be written — and no drafting tickets planned — until this document is approved by the product owner.

### Decision record — 2026-10-06 · Decided by Ian Bruce

**All drafting architecture open items (O-D1–O-D4) are DEFERRED until the drafting phase.**

- **Rationale:** these decisions cannot be made well in the abstract. The serialization format (O-D1), the merge/coauthoring UX (O-D2), the agent-loop home (O-D3), and the provider choice (O-D4) all need to be *seen and played with* before they are locked.
- **Plan:** when the drafting phase arrives (roadmap Phase 8), build a **mock / clickable interface** of the drafting flow first — template → draft instance → agent-fill → HTML preview → section-level edit → approve — and use it to play with the concepts. O-D1–O-D4 are decided *against the mock*, not against prose.
- **Stop gate stays in force:** no drafting implementation until (a) the mock interface is built and reviewed by the product owner, (b) O-D1, O-D2, and O-D3 are decided, and (c) this document is approved. O-D4 (provider choice) may trail if the AI gateway interface is fixed first.
- P-D1–P-D3 remain proposed; they are taken up during or after the mock review as their dependencies dictate.

This supersedes the earlier approval-gate wording (which required O-D1–O-D3 decisions up front). The requirement for decisions stands — the *timing* moves to the mock review, and the mock is the decision instrument.

## 10. Source map

- Product-level drafting commitments: `docs/PRODUCT_BLUEPRINT.md` (§3.4, §6, decision register)
- Drafting-adjacent requirements: `docs/RFI_REQUIREMENTS_MATRIX.md` (DOC-09, DOC-10, DOC-18–DOC-23)
- Roadmap placement: `docs/ROADMAP.md` (Phase 8)
