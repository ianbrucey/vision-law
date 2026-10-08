# 007 — Document management core — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-08

---

## Goal

An attorney can get any case document into Vision Law, find it again, read it on any device, revise it with full history, share it safely, and prove the chain of custody — with the system enforcing the same discipline a records department would.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | Single-shot upload ≤100 MiB: bytes stream to storage, SHA-256 computed during streaming, MIME sniffed (never trusted from extension), identical bytes dedupe to one blob; >100 MiB rejected with 413 before storage | `test_single_upload` — upload 5 MiB PDF → 201, blob stored once; re-upload identical bytes → same blob id, no new blob row; 101 MiB → 413, nothing stored | DOC-01 |
| C-02 | Chunked/resumable upload 100 MiB–5 GiB in 8 MiB chunks: initiate → idempotent per-chunk PUT → resume via received-chunk bitmap → complete with total SHA-256 verification (422 on mismatch, session retained) → cancel with garbage collection; sessions expire after 24h idle | `test_chunked_upload_resume` — upload 200 MiB in chunks, drop connection mid-way, resume with bitmap → completes; wrong final hash → 422, chunks retained; cancel → chunks deleted | DOC-02 |
| C-03 | Every upload is malware-scanned (ClamAV) before availability: infected → `quarantined`, blob moved to inaccessible prefix, audit event + admin notification; engine unreachable → upload stays `scanning`, never silently marked clean, ops alert after 5 min | `test_malware_scan` — EICAR test file → `quarantined`, preview/download → 403; scan engine stopped → upload stays `scanning` after 5 min, alert queued | DOC-03 |
| C-04 | MIME allowlist validation (PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, CSV, PNG, JPG, TIFF, MSG/EML) + technical metadata extraction (page count, dimensions/DPI, properties, native-text vs image-only detection routing to OCR); extraction failure sets `metadata_status: partial` without blocking | `test_metadata_extraction` — upload DOCX → page count + properties extracted; image-only PDF → flagged `needs_ocr`; corrupt file → `partial`, document still usable | DOC-04 |
| C-05 | Previews: PDF via pdf.js (navigation, thumbnails, zoom, find-in-page, text selection) through 15-min signed URLs scoped to the user with permission re-checked on every range request; Office via headless conversion cached per version; images with zoom/rotate | `test_preview_gating` — signed URL works; revoke grant → same URL → 403 on next range request; Office doc → converted preview renders; conversion failure → graceful "preview unavailable" state | DOC-05, DOC-06, DOC-07 |
| C-06 | Download of exact original bytes for any version with original filename, `Content-Disposition: attachment`, `X-Checksum-Sha256` header; every download audited | `test_download_integrity` — download v2 → bytes match upload, checksum header matches SHA-256; `document.downloaded` audit row written | DOC-08 |
| C-07 | Built-in editor to author from scratch (headings, lists, tables, page-break hints, pleading line-numbering toggle); autosave draft every 30s; explicit Save publishes v1 as structured HTML + generated PDF rendition. Content edits on authored documents = new immutable versions; metadata edits never version; uploaded binaries offer "upload new version" instead | `test_editor_publish` — type, autosave draft (no version created), Save → v1 with HTML + PDF rendition; edit → v2; metadata title change → still v2 | DOC-09, DOC-10 |
| C-08 | Per-matter folder tree (create/rename/move, delete empty-only, bulk move/tag/download-as-ZIP); default folders seeded per matter type; every document belongs to exactly one matter; move-matter carries full history; soft delete → per-matter trash (30d) → hard delete; legal hold blocks hard delete | `test_filing_and_trash` — move doc between folders; move-matter → versions + audit intact; delete → trash; restore → all versions back; hold → hard delete → 423 | DOC-11, DOC-12, DOC-13 |
| C-09 | Document list with faceted filtering (type, folder, uploader, date, retention status, version count) + keyword search over title/filename/tags/description; per-user saved views; 50/page. Text extraction pipeline (per-page rows, tsvector FTS, idempotent per version) + OCR for image-only (per-word confidence, low-confidence flagged); full-text search with ranked snippets opening preview at the matched page with hits highlighted; strictly permission-scoped | `test_document_search` — upload native PDF + scanned PDF → OCR text searchable; snippet click opens preview at page; ungranted user's search returns nothing | DOC-14, DOC-15, DOC-16, DOC-17 |
| C-10 | Immutable content-addressed versioning: gapless sequential numbers; blobs deduped by SHA-256; byte-identical upload is a no-op with notice; history view (date/author/size/note, newest first) with preview/download/restore/diff per row; rollback creates N+1 with old bytes + required reason + `restored_from_version`; structured diff for text versions | `test_version_lifecycle` — v1→v2→v3; restore v1 → v4 with old bytes and reason recorded; history never rewritten; diff v2/v3 shows added/removed | DOC-18, DOC-19, DOC-20, DOC-21 |
| C-11 | Template library (stationery only — NOT drafting intelligence): org-level HTML templates with `{{merge_field}}` placeholders and typed field definitions; templates versioned; publishing requires template-editor role. Generate: field form rendered from definitions, `matter_field` pre-filled from the matter, required validation; creates authored v1 linked to source template version | `test_template_generate` — create template with `{{client_name}}` → generate for matter → v1 with merged value, linked to template version; publish without template-editor role → 403 | DOC-22, DOC-23 |
| C-12 | Sharing: internal document grants (viewer/commenter/editor; effective = max(matter role, grant)); external secure links (unguessable token, 7d default/30d max expiry, optional password with 5-attempt lockout, download toggle, pinned version, separate hardened endpoint, every access audited, instant revocation); encrypted export packages (AES-256 ZIP + signed JSON manifest with per-file SHA-256) | `test_sharing` — grant viewer to user → can preview not edit; external link with password → wrong password ×5 → lockout; revoke → link dead; export manifest verifies | DOC-24, DOC-25, DOC-26 |
| C-13 | Retention: admin-authored policies per category/matter-type (period, trigger, disposition, legal basis), versioned, with a simulator previewing affected documents before activation; manual + nightly auto-flagging; legal hold blocks hard delete (423), version purge, and matter archival; release requires legal-hold role + logged reason; disposition queue (destroy/extend/archive) with dual-control destruction (two distinct approvers) then tombstone | `test_retention_and_holds` — policy simulator previews matches; hold → delete → 423; release without role → 403; destroy needs two distinct approvals → tombstone retained | DOC-27, DOC-28, DOC-29 |
| C-14 | Append-only document audit trail: every mutation + every sensitive read (view/download/preview of flagged docs, share, restore, destroy) logged with actor/action/document/version/IP/user-agent/timestamp; per-document Activity tab; matter-level CSV export | `test_document_audit` — upload→preview→download→restore→share each writes exactly one row; CSV export contains the full chain | DOC-30 |
| C-15 | Mobile-first: every screen fully usable at 390px — upload, list/facets, preview, version history, editor, share dialogs; touch targets ≥44px; no horizontal page scroll; tables scroll inside their cards | `test_mobile_layout` — headless 390px render of each screen: no horizontal overflow, all primary actions reachable and ≥44px, verified against the mockup | — (standing requirement, Ian 2026-10-08) |

## Blueprint anchors

- [D] PRODUCT_BLUEPRINT §5 — documents are matter-scoped work product; the matter is the authorization boundary (006).
- [D] ROADMAP Phase 3 — exit criteria: upload → process → find → revise → restore, provable in the audit trail; identical bytes dedupe; permission-denied users get nothing.
- [D] 006-D04 — activity/audit as read model; document activity follows the same pattern (no separate activity table).
- [D] UI_Standards [D] — all views use `<x-ui.*>`; light-only; new patterns proposed to UI_Standards first.
- [D] 007-D01 — local-disk object storage behind the `DocumentStore` abstraction (S3 path designed, not deployed).
- [D] 007-D03 — administrative scope boundary (Ian explicit 2026-10-08): no drafting intelligence in this phase. Template library is merge-field stationery; the editor is a typing surface. Tickets smelling like drafting get flagged out of scope.
- [D] 007-D04 — mobile-first gate: 390px verification is a claim (C-15), not an afterthought.
- [O] None load-bearing. OCR provider choice (native Tesseract vs API) is settled by 007-D02 (native Tesseract first, provider interface for later).

## Security classification

- **Actors:** org_admin, attorney, paralegal, viewer, outside_counsel (explicit grant only), anonymous (external links only).
- **Data classes touched:** privileged (document contents — pleadings, discovery), work-product (drafts, annotations), confidential (client data in templates), externally shared (link shares, exports).
- **Allow events (audit):** `document.uploaded`, `document.scanned`, `document.quarantined`, `document.previewed`, `document.downloaded`, `document.version.created`, `document.version.restored`, `document.metadata.updated`, `document.moved`, `document.trashed`, `document.restored`, `document.destroyed`, `document.shared`, `document.share.revoked`, `document.share.accessed`, `document.exported`, `document.retention.flagged`, `document.hold.placed`, `document.hold.released`, `document.disposition.decided`, `template.created`, `template.published`, `template.used`.
- **Denial events (audit):** `document.access.denied`, `document.download.denied`, `document.share.denied`, `document.destroy.denied` (incl. hold-blocked 423), `document.version.restore.denied`.
- **Leak sentinels:** document titles, filenames, and extracted text must never appear in HTML/JSON/logs for actors without matter access; share tokens and token hashes never in logs; quarantine contents never previewable; cross-org document titles.

## Evidence and fixtures

Canonical fixtures in `04-fixtures.json`: a synthetic matter with documents spanning types (native PDF, scanned PDF needing OCR, DOCX, image, authored doc with 3 versions, quarantined file, trashed doc, held doc, restricted doc), users across roles and grants (incl. cross-tenant adversary, unassigned viewer, outside counsel with single-matter grant), a template with merge fields, an expired external share link, and a retention policy with flagged documents. Adversarial: permission-denied preview/download/search attempts; byte-identical re-upload; EICAR test file for the scan path.

## Pre-mortem

- **Likely failure:** the processing pipeline (scan → extract/OCR → index) will be the flakiest part — external binaries (ClamAV, LibreOffice, Tesseract) not installed, hanging, or slow. Mitigation: 007-D02 provisions natively via apt in T-01; every stage has an explicit status (`scanning`, `extracting`, `needs_ocr`, `partial`) and the document is never silently marked clean/ready.
- **Assumption:** local disk is sufficient for the RFI-demo phase (136G available). Might be false at production scale. Mitigation: 007-D01 — all file I/O behind `DocumentStore`; swapping to S3 is a config + driver change, not a rewrite.
- **Unknown:** LibreOffice headless conversion fidelity for complex DOCX (tracked changes, embedded objects). Mitigation: conversion failure is a specified graceful state ("preview unavailable — download instead"), not an error path; fidelity issues don't block the phase.
- **Mitigation (scope):** the drafting boundary (007-D03) is enforced at the brief level — any ticket drifting toward content intelligence (summarization, clause extraction, exhibit dedup/markup, agent loops) is flagged out of scope and moved to the Phase 8 backlog.

## Non-goals

- Drafting intelligence of any kind: no JSON content model, no agent fill, no clause extraction, no summarization, no exhibit/discovery dedup or markup workflows (Ian explicit 2026-10-08; deferred to Phase 8).
- Real-time co-editing; the editor is single-author with autosave drafts.
- Email delivery of shares/invitations (still unconfigured; copy-paste links remain the path).
- S3/MinIO deployment (designed, not deployed — 007-D01).
- SSO, idempotency keys (still deferred from 001).

## Approval gate

- [ ] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [ ] Product owner approved — **approver:** Ian Bruce · **date:** __________
