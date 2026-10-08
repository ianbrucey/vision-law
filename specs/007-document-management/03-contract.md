# 007 — Document management core — Contract

**Status:** DRAFT
**Date:** 2026-10-08

> Every operation names its authorization rule and its audit event (allow and deny). Every service method lists inputs and the validation error for each bad input. Views may request only what this contract exposes.

---

## Conventions

- All document routes nest under `/matters/{matter}` and carry `RequireMatterAccess` (006) before any data access. Document action levels: `:view` < `:comment` < `:edit` < `:manage`, checked via the extended `AccessControl` (see 01-archaeology).
- Denial semantics: 404-not-403 for existence (006 convention); 403 for forbidden-but-known; 409 for state conflicts; 422 for validation; 423 Locked for legal-hold blocks.
- Every mutation writes its audit event in the same transaction (006-D04 pattern).

## Routes

### Upload

| Method | Path | Name | Authz | Audit (allow / deny) |
|---|---|---|---|---|
| POST | `/matters/{matter}/documents/upload` | `documents.upload` | `:edit` | `document.uploaded` / `document.access.denied` |
| POST | `/matters/{matter}/documents/uploads/init` | `documents.uploads.init` | `:edit` | `document.upload.initiated` / deny as above |
| PUT | `/matters/{matter}/documents/uploads/{session}/chunks/{n}` | `documents.uploads.chunk` | `:edit` (owns session) | — (chunk writes; completion audited) |
| POST | `/matters/{matter}/documents/uploads/{session}/complete` | `documents.uploads.complete` | `:edit` (owns session) | `document.uploaded` / `document.upload.hash_mismatch` (422) |
| DELETE | `/matters/{matter}/documents/uploads/{session}` | `documents.uploads.cancel` | `:edit` (owns session) | `document.upload.cancelled` |

**Requests.**
- `upload`: `file` (required, ≤100 MiB → 413 otherwise), `folder_id` (nullable, must belong to matter → 422), `title` (nullable, defaults to filename), `description`, `tags[]`.
- `uploads.init`: `filename`, `size` (100 MiB–5 GiB → 422 outside), `sha256` (expected total), `mime_hint` (advisory only).
- `uploads.chunk`: raw bytes, exactly 8 MiB except final; idempotent per (`session`, `n`) — re-PUT of identical chunk → 200 no-op; differing bytes → 409.
- `uploads.complete`: server reassembles, verifies total SHA-256 → mismatch: 422 `hash_mismatch`, session retained for retry.

### Documents

| Method | Path | Name | Authz | Audit |
|---|---|---|---|---|
| GET | `/matters/{matter}/documents` | `documents.index` | `:view` | — (list; sensitive reads audited per DOC-30 only for flagged docs) |
| GET | `/matters/{matter}/documents/create` | `documents.create` | `:edit` | — |
| POST | `/matters/{matter}/documents` | `documents.store` | `:edit` | `document.created` (authored) |
| GET | `/matters/{matter}/documents/{document}` | `documents.show` | `:view` | `document.viewed` (flagged docs only) |
| PATCH | `/matters/{matter}/documents/{document}` | `documents.update` | `:edit` | `document.metadata.updated` — metadata-only, never versions |
| DELETE | `/matters/{matter}/documents/{document}` | `documents.destroy` | `:manage` | `document.trashed` / `document.destroy.denied` (hold → 423) |
| POST | `/matters/{matter}/documents/{document}/restore` | `documents.restore` | `:manage` | `document.restored` |
| GET | `/matters/{matter}/documents/{document}/preview` | `documents.preview` | `:view` | `document.previewed` (flagged docs) |
| GET | `/matters/{matter}/documents/{document}/download` | `documents.download` | `:view` | `document.downloaded` (always) |
| POST | `/matters/{matter}/documents/{document}/move` | `documents.move` | `:edit` | `document.moved` (+ `document.matter_moved` when matter changes) |
| GET | `/matters/{matter}/documents/{document}/versions` | `documents.versions.index` | `:view` | — |
| POST | `/matters/{matter}/documents/{document}/versions/{version}/restore` | `documents.versions.restore` | `:edit` | `document.version.restored` / `document.version.restore.denied` |
| GET | `/matters/{matter}/documents/{document}/versions/{a}/diff/{b}` | `documents.versions.diff` | `:view` | — |

**Validation errors.** `title` required on authored create (422 `title_required`); `folder_id` must belong to the matter (422 `folder_not_in_matter`); restore requires non-empty `reason` (422 `reason_required`); restore of current version → 200 no-op with notice; move to another matter re-evaluates permissions at move time — mover must have `:edit` on destination (403 otherwise).

### Folders

| Method | Path | Name | Authz | Audit |
|---|---|---|---|---|
| POST | `/matters/{matter}/folders` | `folders.store` | `:edit` | `folder.created` |
| PATCH | `/matters/{matter}/folders/{folder}` | `folders.update` | `:edit` | `folder.renamed` / `folder.moved` |
| DELETE | `/matters/{matter}/folders/{folder}` | `folders.destroy` | `:edit` | `folder.deleted` — non-empty → 422 `folder_not_empty` |

### Search

| Method | Path | Name | Authz | Notes |
|---|---|---|---|---|
| GET | `/search/documents` | `search.documents` | auth | Facets: `type`, `folder_id`, `uploader`, `date_from/to`, `retention_flagged`, `matter_id`; `q` keyword over title/filename/tags/description (trigram); `content` full-text over extracted/OCR text with snippets; permission-scoped before pagination; 50/page |

Route placed at `/search/documents` (not nested) — mirrors 006-D12's `/search/matters` reasoning.

### Templates (stationery — 007-D03)

| Method | Path | Name | Authz | Audit |
|---|---|---|---|---|
| GET/POST | `/templates`, `/templates/create` | `templates.*` | `:view` / template-editor role for publish | `template.created`, `template.published` |
| GET | `/templates/{template}` | `templates.show` | `:view` | — |
| POST | `/templates/{template}/generate` | `templates.generate` | `:edit` on target matter | `template.used` → creates authored document v1 |

`generate` request: `matter_id` (required, must be accessible → 403/404), `fields` (object; required fields validated → 422 `field_required:{name}`).

### Sharing

| Method | Path | Name | Authz | Audit |
|---|---|---|---|---|
| POST | `/matters/{matter}/documents/{document}/grants` | `documents.grants.store` | `:manage` | `document.shared` (internal) |
| DELETE | `/matters/{matter}/documents/{document}/grants/{grant}` | `documents.grants.destroy` | `:manage` | `document.share.revoked` |
| POST | `/matters/{matter}/documents/{document}/links` | `documents.links.store` | `:manage` | `document.share.created` (external) |
| DELETE | `/matters/{matter}/documents/{document}/links/{link}` | `documents.links.destroy` | `:manage` | `document.share.revoked` |
| POST | `/matters/{matter}/documents/export` | `documents.export` | `:edit` | `document.exported` |

External access (no session): `GET /s/{token}` → `shares.show` (hardened endpoint); `POST /s/{token}/download` → `shares.download`. Every access audited with IP/timestamp (`document.share.accessed`); 5 bad passwords → 15-min lockout; expired/revoked → identical 404.

### Retention

| Method | Path | Name | Authz | Audit |
|---|---|---|---|---|
| GET/POST | `/admin/retention/policies` | `retention.policies.*` | org_admin | `retention.policy.created/activated` |
| POST | `/admin/retention/policies/{policy}/simulate` | `retention.policies.simulate` | org_admin | — (read-only preview) |
| POST | `/matters/{matter}/documents/{document}/hold` | `documents.hold.store` | legal-hold role | `document.hold.placed` |
| POST | `/matters/{matter}/documents/{document}/hold/release` | `documents.hold.release` | legal-hold role + reason | `document.hold.released` |
| GET/POST | `/admin/retention/disposition` | `retention.disposition.*` | org_admin | `document.disposition.decided`; destroy needs 2 distinct approvers |

### Editor

Authored documents use the documents routes above with `kind=authored`; autosave drafts POST to `/matters/{matter}/documents/{document}/draft` (`documents.draft.update`, `:edit`, no version created, no audit row — drafts are ephemeral until Save).

## Services

### `DocumentStore` (007-D01)
- `put(string $contents, array $meta): Blob` — streams to local disk, returns blob record (dedupe by SHA-256 inside).
- `get(Blob $blob): StreamInterface` — never exposes paths.
- `delete(Blob $blob): void` — only when `refcount` = 0.
- `quarantine(Blob $blob): void` — moves to inaccessible prefix.
- Controllers/jobs never touch `Storage` directly (architecture test).

### `DocumentProcessingPipeline`
- `scan(DocumentVersion $v): void` — ClamAV; infected → quarantine + notify; engine down → stays `scanning`.
- `extract(DocumentVersion $v): void` — metadata + per-page text rows; idempotent; failure → `partial`.
- `ocr(DocumentVersion $v): void` — Tesseract on image-only pages; per-page confidence; feeds `document_text_pages`.
- Each stage is a queued job; status visible per version; retries with backoff; poison-pill → `failed` + alert.

### `RetentionService` / `LegalHoldService`
- `evaluateNightly(): void` — flags documents at thresholds (scheduled command).
- `placeHold`, `releaseHold` (reason required), `holdsBlocking(Document $d): bool` — consulted by destroy/purge/archive paths.
- `decideDisposition` — enforces dual-control for destroy; writes tombstone.

## Component props (Blade)

Views may request only: `document` (with `currentVersion`, `folder`, `grants`, `versions_count`), `versions` (paginated), `folders` (tree), `facets` (counts), `templates`, `shares`, `policies`, `flags`, `holds`. No raw blobs, paths, tokens, or hashes reach views. Share creation responses carry the one-time plaintext link (never re-rendered).

## Error catalog (additions)

| Code | HTTP | Meaning |
|---|---|---|
| `hash_mismatch` | 422 | Chunked completion SHA-256 mismatch; session retained |
| `folder_not_empty` | 422 | Folder delete with documents inside |
| `folder_not_in_matter` | 422 | Folder from another matter |
| `reason_required` | 422 | Restore/disposition without reason |
| `field_required:{name}` | 422 | Template generate missing required field |
| `quarantined` | 403 | Preview/download of infected document |
| `hold_locked` | 423 | Hard delete / purge / archival under legal hold |
| `share_locked_out` | 429 | External link password attempts exceeded |

## Interfaces for Phase 4 (deadlines)

- `Document::class` + `document_id` stable for deadline rule anchors (CAL rules will reference documents).
- `document.retention.flagged` event exists for the deadline engine's escalation hooks.
- `GET /matters/{matter}/documents` returns `retention_flagged_at` per row — the Phase 4 UI will surface it without new endpoints.
