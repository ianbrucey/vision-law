# 007 — Document management core — Plan

**Status:** DRAFT
**Date:** 2026-10-08

> Atomic tickets, backend-out. Each ticket lists files, dependencies, and acceptance = its Verdict (named tests). Do not start the next ticket on a red gate. The executing agent never writes its own verdicts.

---

## T-01 — Storage foundation + schema

**Files:** `database/migrations/*_create_documents_*.php` (12 tables), `app/Services/DocumentStore.php`, `app/Services/LocalDocumentStore.php`, `config/document.php`, `app/Models/Document*.php` (7 models).
**Also:** `apt install clamav-daemon liboffice-headless tesseract-ocr` natively (007-D02); verify `clamscan`, `soffice`, `tesseract` binaries.
**Dependencies:** none (baseline green).
**Verdicts:** `test_document_store_dedupe` (identical bytes → one blob row, refcount 2), `test_migrations_rollback` (all 12 roll back cleanly), `test_versions_immutable` (direct UPDATE on `document_versions` → DB error).

## T-02 — Upload pipeline + malware scan

**Files:** `app/Http/Controllers/DocumentUploadController.php`, `app/Services/ChunkedUploadService.php`, upload session table (add to T-01 migrations or its own), `routes/web.php` (upload section).
**Dependencies:** T-01.
**Verdicts:** `test_single_upload` (C-01), `test_chunked_upload_resume` (C-02), `test_malware_scan` (C-03, EICAR fixture).

## T-03 — Metadata, previews, download

**Files:** `app/Services/MetadataExtractor.php`, `app/Http/Controllers/DocumentPreviewController.php`, `resources/views/documents/preview.blade.php`, signed-URL service, LibreOffice conversion job.
**Dependencies:** T-02.
**Verdicts:** `test_metadata_extraction` (C-04), `test_preview_gating` (C-05), `test_download_integrity` (C-06).

## T-04 — Editor + templates (stationery only)

**Files:** `app/Http/Controllers/DocumentEditorController.php`, `app/Http/Controllers/TemplateController.php`, `app/Models/DocumentTemplate.php`, `resources/views/documents/editor.blade.php`, `resources/views/templates/**`.
**Dependencies:** T-01. **Scope guard:** merge fields only; any content-intelligence drift → flag out of scope (007-D03).
**Verdicts:** `test_editor_publish` (C-07), `test_template_generate` (C-11).

## T-05 — Folders, filing, trash, document list

**Files:** `app/Models/DocumentFolder.php`, `app/Http/Controllers/DocumentController.php` (index/show/update/destroy/restore/move), `app/Http/Controllers/FolderController.php`, `resources/views/documents/index.blade.php`.
**Dependencies:** T-01.
**Verdicts:** `test_filing_and_trash` (C-08), `test_document_list_facets` (C-09 list half).

## T-06 — Extraction/OCR pipeline + full-text search

**Files:** `app/Services/DocumentProcessingPipeline.php`, `app/Jobs/ScanDocument.php`, `app/Jobs/ExtractDocumentText.php`, `app/Jobs/OcrDocumentPages.php`, `app/Http/Controllers/DocumentSearchController.php`.
**Dependencies:** T-02, T-03.
**Verdicts:** `test_extraction_pipeline` (idempotent per version; `partial` on failure), `test_ocr_flagging` (low-confidence pages flagged), `test_document_search` (C-09 search half: snippets, permission-scoped, zero hits for ungranted user).

## T-07 — Versioning: history, rollback, diff

**Files:** `app/Services/DocumentVersioningService.php`, `app/Http/Controllers/DocumentVersionController.php`, `resources/views/documents/versions.blade.php`, diff renderer.
**Dependencies:** T-01, T-05.
**Verdicts:** `test_version_lifecycle` (C-10).

## T-08 — Sharing: grants, links, export

**Files:** `app/Models/DocumentShare.php`, `app/Models/DocumentGrant.php`, `app/Http/Controllers/DocumentShareController.php`, hardened `/s/{token}` routes, `app/Services/DocumentExportService.php`, `resources/views/documents/share.blade.php`.
**Dependencies:** T-05.
**Verdicts:** `test_sharing` (C-12).

## T-09 — Retention policies, legal holds, disposition

**Files:** `app/Models/RetentionPolicy.php`, `app/Models/LegalHold.php`, `app/Services/RetentionService.php`, `app/Services/LegalHoldService.php`, `app/Console/Commands/EvaluateRetention.php` (nightly), `app/Http/Controllers/RetentionController.php`, tombstone migration.
**Dependencies:** T-05.
**Verdicts:** `test_retention_and_holds` (C-13).

## T-10 — Audit trail + mobile gate + freeze

**Files:** audit event wiring review (all previous tickets), `tests/Feature/DocumentMobileTest.php`, `dev-journal/` entries, `docs/UI_Standards.md` pattern proposals.
**Dependencies:** T-01–T-09.
**Verdicts:** `test_document_audit` (C-14), `test_mobile_layout` (C-15: 390px renders of every screen, no horizontal overflow, targets ≥44px).
**Freeze:** fold schema into domain model, record decisions, close 05-ui.md approval loop.

## Forbidden edits (all tickets)

- `app/Models/Matter.php` and matter migrations (006-owned).
- `app/Services/AuditLogger.php` and audit trigger (001-owned).
- Any drafting-intelligence code paths — flag and stop (007-D03).
