# 007 — Document management core — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-08
**Brief:** `00-brief.md` (DRAFT — archaeology drafted alongside per orchestrator instruction; brief approval gates execution, not this scan)

> Read the code — do not guess from memory. Versions recorded from the actual lockfiles where checked.

---

## Findings

| Thing | Location | Verdict | Constraint / note |
|---|---|---|---|
| `Matter` model + `matters` table (`lifecycle_state`, `MAT-YYYY-NNNN`) | `app/Models/Matter.php` | REUSE | Documents key off `matter_id`; the matter is the authorization boundary — no changes |
| `RequireMatterAccess` middleware + `AccessControl::authorize` | `app/Http/Middleware/…`, `app/Services/AccessControl.php` | EXTEND | Add document action levels (`:view`, `:comment`, `:edit`, `:manage`) alongside matter levels; keep BC |
| `MatterDocumentLog` (append-only register) | `app/Models/MatterDocumentLog.php` | REUSE | The correspondence register stays; document objects are separate (log rows may reference `document_id`) |
| `matter_grants` table | 001/006 | REUSE | Assignment mechanism unchanged; document grants are a separate, narrower table |
| `AuditLogger` + append-only `audit_events` (DB trigger) | `app/Services/AuditLogger.php` | REUSE | All document mutations/reads write through it; no new audit table |
| `x-ui.*` component library (table, field, modal, status, pagination…) | `resources/views/components/ui/` | REUSE | All new views use them; new patterns (file dropzone, preview toolbar) proposed to UI_Standards first |
| `DocumentStore` service | — | NEW | All file I/O goes through it; controllers never touch storage directly (007-D01) |
| `documents`, `document_versions`, `document_blobs`, `document_folders`, `document_text_pages`, `document_templates`, `document_shares`, `document_grants`, `retention_policies`, `retention_flags`, `legal_holds`, `disposition_queue` | — | NEW | Per 02-schema-delta.md; every matter-owned table names `matter_id` + `org_id` |
| ClamAV / LibreOffice / Tesseract | not installed | NEW (provision) | 007-D02: `apt install` natively in T-01; no Docker. PHP has fileinfo/gd/zip already |
| pdf.js | — | NEW | Vendored via npm (no CDN — self-contained asset policy); used only in the preview view |
| Old vision Python OCR/chunking pipeline (`/opt/vision`) | reference only | CONFLICT (do not port) | Abandoned build vehicle; patterns may inform but no code is copied |
| `FILESYSTEM_DISK=local`, 136G available on `/` | `.env`, server | REUSE (config) | 007-D01: local disk behind the abstraction; S3 env keys present but empty — designed, not deployed |

## Architectural doors this feature must use

- All file operations go through `DocumentStore` — controllers/jobs never touch storage directly.
- All matter access goes through `RequireMatterAccess` before any data access; document-level checks compose on top (never replace).
- Audit events are written only through `AuditLogger`.
- Uploaded bytes are never trusted: MIME is sniffed, scans are mandatory, failures are explicit states.
- Tokens (share links) are hashed at rest; plaintext appears only in the one-time display.
- No drafting intelligence: any ticket touching content understanding beyond extraction/OCR is out of scope (007-D03).

## Files to touch

| File | Action | Owner | Reason |
|---|---|---|---|
| `database/migrations/2026_10_0*_create_documents_*.php` (12 tables) | create | T-01 worker | Schema per 02-schema-delta.md |
| `app/Services/DocumentStore.php` (+ `LocalDocumentStore`) | create | T-01 worker | Storage abstraction (007-D01) |
| `app/Models/Document*.php` (7 models) | create | T-01 worker | Domain models |
| `app/Http/Controllers/Document*.php` | create | ticket workers | Per 03-contract.md |
| `app/Services/DocumentProcessingPipeline.php` + jobs | create | T-02/T-06 workers | Scan → extract/OCR → index stages |
| `app/Services/RetentionService.php`, `LegalHoldService.php` | create | T-09 worker | Policies, flags, holds, disposition |
| `routes/web.php` (document section) | edit | orchestrator | Route registration (shared file) |
| `resources/views/documents/**` | create | ticket workers | x-ui.* only |
| `config/document.php` | create | T-01 worker | Limits, allowlists, engine config |
| `docs/UI_Standards.md` | edit (propose) | orchestrator | Dropzone + preview-toolbar patterns |

## Protected files

- `app/Models/Matter.php`, matter migrations — owned by 006; documents reference, never alter.
- `app/Services/AuditLogger.php`, audit migrations/trigger — owned by 001; append-only.
- `app/Services/AccessControl.php` — EXTEND only via additive action levels; orchestrator reviews.
- `routes/web.php` auth/matter sections — orchestrator-owned regions.
- `/opt/vision`, `/opt/haven` — never touched.

## Baseline

- Tests: `php artisan test` — 186 passed / 2694 assertions (post-006 merge `a042c7e`), 0 failures
- Architecture tests: green (incl. `VisionUiTest` doors)
- Pint: `vendor/bin/pint --test` — clean (152 files)
- PHPStan: level 7 — no errors
- Asset build: `npm run build` — success
- Framework: laravel/framework 13.x (per composer.lock); PHP 8.4; Postgres 18 + pgvector + pg_trgm
- Server: 136G free; PHP exts fileinfo/gd/zip present; no ClamAV/LibreOffice/Tesseract (provisioned in T-01)
