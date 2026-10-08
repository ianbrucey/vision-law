# 007 — Document management core — Decision log

> One home per fact. Formal decisions live here; the dev journal links here rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-08 | 007-D01 | Object storage = local disk behind the `DocumentStore` abstraction; S3-compatible path designed, not deployed | Ian's standing preference for native over Docker; 136G free on the single server; MinIO was skipped on the vision app for the same reasons; S3 env keys exist but empty | MinIO in Docker now; S3 from day one | All file I/O behind `DocumentStore`; swapping drivers is config, not rewrite; no new infrastructure for the RFI-demo phase | [D] |
| 2026-10-08 | 007-D02 | Provision ClamAV, LibreOffice headless, and Tesseract natively via `apt` (no Docker) in T-01 | None installed on the server; PHP has fileinfo/gd/zip | Docker sidecars; API-based scan/OCR providers | Graceful-degradation contracts still hold (scan unreachable → stays `scanning`; conversion failure → graceful state); provider interface left open for later | [D] |
| 2026-10-08 | 007-D03 | Administrative scope boundary: NO drafting intelligence in this phase | Ian explicit 2026-10-08: document management stays administrative (ingestion, storage, preview, versions, search, sharing, retention) | Let template/editor scope creep into content intelligence | Template library = merge-field stationery; editor = typing surface; tickets smelling like drafting get flagged to the Phase 8 backlog | [D] |
| 2026-10-08 | 007-D04 | Mobile-first is a first-class claim (C-15): every screen verified at 390px, touch targets ≥44px, no horizontal page scroll | Ian standing requirement 2026-10-08: assume phone-only users; marketability | Desktop-first with phone as follow-up | Mockup designed phone-first; `test_mobile_layout` gates the phase | [D] |
| 2026-10-08 | 007-D05 | Chunked upload protocol: 8 MiB chunks, idempotent per-chunk PUT, resume bitmap, total SHA-256 verify on complete (422 + session retained on mismatch), 24h idle session expiry with garbage collection | DOC-02 | Larger chunks (fewer requests, worse resume granularity) | Contract §Upload; client must handle 409 on divergent chunk re-PUT | [D] |
| 2026-10-08 | 007-D07 | The 30-day trash purge is the single sanctioned destruction path through the 007-D06 immutability trigger: a transaction-scoped `SET LOCAL document_versions.purge_allowed = 'on'` GUC bypass, evaluated inside the trigger before rejecting the DELETE; the setting dies with the transaction and is never enabled outside `DocumentFilingService::hardDelete()` | DOC-28 retention execution; the immutability trigger would otherwise make legal hard-delete impossible | REVOKE-based exceptions for the app role (too broad); a privileged purge role (credential sprawl) | Purge path is auditable (`document.destroyed` per row) and cannot be triggered accidentally; legal holds are checked in PHP before the bypass is ever set | [D] |
| 2026-10-08 | 007-D08 | Default per-matter folder sets are seeded lazily on first folder-list/document-list access (`DocumentFolderService::ensureDefaults`), not at matter creation | Matter creation is 006-owned (T-05 is forbidden from touching it); seeding on read keeps 006's write path untouched | Seed in a 006 matter-created event/listener (cross-spec coupling, forbidden) | Folder tree is always complete on read; idempotent `firstOrCreate` per (matter, parent, name); sets vary by matter type (litigation → Pleadings/Discovery/Correspondence; transactional → Contracts/Correspondence/Due Diligence; fallback → Correspondence/Contracts) | [D] |
| 2026-10-08 | 007-D06 | Version numbers gapless sequential per document; rollback creates N+1 with old bytes (history never rewritten); `document_versions` is DB-immutable (REVOKE UPDATE/DELETE for app role) | DOC-18/20; same immutability pattern as the 001 audit trigger | Allow version mutation; soft-delete versions | Restore requires non-empty reason; `restored_from_version` lineage | [D] |

<RULES:>
- Tag each decision [D]/[P]/[O] using the same meanings as the system docs.
- A decision that reverses an earlier one marks the old row [S] superseded and links the new ID — never delete or rewrite history.
- Decisions that change a governing system document are noted here AND proposed as an edit to that document.
