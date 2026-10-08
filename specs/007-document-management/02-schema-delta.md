# 007 — Document management core — Schema delta

**Status:** DRAFT
**Date:** 2026-10-08

> Against the domain model: every matter-owned table names `matter_id` plus the owning `org_id`. UUID PKs throughout (001 convention). All tables get `created_at`/`updated_at`; soft deletes where noted.

---

## New tables

### `documents`
The document object. One row per logical document; versions hang off it.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `org_id` | uuid FK → organizations | tenant boundary |
| `matter_id` | uuid FK → matters | exactly one matter (DOC-13) |
| `folder_id` | uuid FK → document_folders, nullable | |
| `title` | text | user-facing title |
| `description` | text, nullable | |
| `tags` | text[] | keyword search (DOC-14) |
| `kind` | text CHECK (`uploaded`,`authored`,`generated`) | authored = editor; generated = from template |
| `current_version_id` | uuid FK → document_versions, nullable | set after first version |
| `status` | text CHECK (`processing`,`ready`,`quarantined`,`trash`) | `scanning`/`extracting` tracked per-version; doc-level rollup |
| `metadata_status` | text CHECK (`ok`,`partial`) default `ok` | DOC-04 |
| `retention_flagged_at` | timestamptz, nullable | DOC-28 |
| `created_by` | uuid FK → users | |
| `deleted_at` | timestamptz, nullable | soft delete → trash (DOC-11) |

### `document_blobs`
Content-addressed storage. One row per unique byte sequence.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `sha256` | char(64) UNIQUE | dedupe key (DOC-01, DOC-18) |
| `size` | bigint | |
| `mime_sniffed` | text | never trusts extension (DOC-01) |
| `storage_path` | text | `DocumentStore`-managed, never exposed |
| `refcount` | integer | incremented per version referencing |
| `quarantined` | boolean default false | infected blobs isolated (DOC-03) |

### `document_versions`
Immutable. Every content change = new row, gapless `version_number` per document.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `document_id` | uuid FK → documents | |
| `version_number` | integer | gapless per document (007-D06) |
| `blob_id` | uuid FK → document_blobs | |
| `change_note` | text, nullable | required on restore (DOC-20) |
| `restored_from_version_id` | uuid FK → document_versions, nullable | rollback lineage |
| `processing_status` | text CHECK (`pending`,`scanning`,`extracting`,`ocr`,`ready`,`failed`) | pipeline stage per version |
| `page_count` | integer, nullable | DOC-04 |
| `created_by` | uuid FK → users | |
| `created_at` | timestamptz | no `updated_at` — immutable |

DB-level guard: REVOKE UPDATE/DELETE on `document_versions` for the app role (same pattern as the audit trigger, 001) — immutability enforced in the database, not just the app.

### `document_folders`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `org_id`, `matter_id` | uuid FK | |
| `parent_id` | uuid FK → document_folders, nullable | tree |
| `name` | text | |
| `deleted_at` | timestamptz, nullable | delete empty-only enforced in service |

### `document_text_pages`
Per-page extracted/OCR text, linked to the version (idempotent per version).

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `version_id` | uuid FK → document_versions | |
| `page_number` | integer | |
| `text` | text | |
| `text_tsv` | tsvector | FTS index (DOC-15) |
| `ocr_confidence` | numeric, nullable | per-page; low-confidence flagged (DOC-16) |

UNIQUE(`version_id`, `page_number`). GIN index on `text_tsv`.

### `document_templates` (stationery — 007-D03)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `org_id` | uuid FK | |
| `name` | text | |
| `body_html` | text | HTML with `{{merge_field}}` placeholders |
| `field_definitions` | jsonb | typed fields: text/date/number/party/matter_field, required, default |
| `version` | integer | templates versioned like documents |
| `status` | text CHECK (`draft`,`published`) | publishing requires template-editor role |
| `created_by` | uuid FK → users | |

### `document_shares`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `document_id` | uuid FK → documents | |
| `version_id` | uuid FK → document_versions | pinned version (DOC-25) |
| `token_hash` | char(64) UNIQUE | SHA-256 of the token; plaintext never stored |
| `expires_at` | timestamptz | default +7d, max +30d |
| `password_hash` | text, nullable | optional; 5-attempt lockout |
| `allow_download` | boolean default true | |
| `revoked_at` | timestamptz, nullable | instant revocation |
| `created_by` | uuid FK → users | |

### `document_grants`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `document_id` | uuid FK → documents | |
| `user_id` | uuid FK → users | (teams deferred — column reserved as `team_id` nullable, unused) |
| `level` | text CHECK (`viewer`,`commenter`,`editor`) | effective permission = max(matter role, grant) |
| `created_by` | uuid FK → users | |

### `retention_policies`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `org_id` | uuid FK | |
| `name` | text | |
| `category` | text | document category the rule applies to |
| `matter_type` | text, nullable | |
| `retention_period` | interval | |
| `trigger` | text CHECK (`matter_close`,`document_date`,`fixed_date`) | |
| `disposition` | text CHECK (`destroy`,`review`,`archive`) | |
| `legal_basis` | text | |
| `version` | integer | policies versioned |
| `status` | text CHECK (`draft`,`active`) | |

### `retention_flags`, `legal_holds`, `disposition_queue`

| Table | Key columns |
|---|---|
| `retention_flags` | `id`, `document_id` FK, `policy_id` FK nullable (manual flags have none), `reason` text, `flagged_at` |
| `legal_holds` | `id`, `org_id` FK, `matter_id` FK nullable, `document_id` FK nullable, `reason` text, `created_by` FK, `released_at`/`released_by`/`release_reason` nullable |
| `disposition_queue` | `id`, `document_id` FK, `action` CHECK (`destroy`,`extend`,`archive`), `requested_by` FK, `approvals` jsonb (array of {approver_id, decided_at}), `status` CHECK (`pending`,`approved`,`rejected`,`executed`), `new_retention_date` nullable (extend), `decided_at` nullable |

Permanent tombstones: `document_tombstones` (`id`, `org_id`, `matter_id`, `title`, `sha256`, `destroyed_at`, `destroyed_by`, `approvals` jsonb) — retained after dual-control destruction (DOC-29).

## Deltas to existing tables

- `matter_document_log`: add nullable `document_id` FK → documents (log rows may reference the object; register stays append-only, 006).
- None to `matters`, `audit_events`, `matter_grants`.

## Indexes

- `documents(org_id, matter_id, status)`, `documents` trigram GIN on (`title`, array_to_string(`tags`)) — DOC-14.
- `document_text_pages` GIN on `text_tsv` — DOC-15/17.
- `document_versions(document_id, version_number)` unique.
- `document_shares(token_hash)` unique; `legal_holds(document_id)` partial where `released_at IS NULL`.
