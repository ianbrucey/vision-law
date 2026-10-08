# 006 — Matter model and lifecycle — Schema delta

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Archaeology:** `01-archaeology.md`

> There is no `DOMAIN_MODEL.md` yet — the domain model lives in `docs/PRODUCT_BLUEPRINT.md` §4 ("the matter as central object"). Accepted deltas here are folded into the blueprint at Freeze.

---

## Tables

### `matters` — ALTER

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `lifecycle_state` | varchar(30) | NOT NULL | `'INTAKE'` | The state machine; replaces `status` |
| `matter_type` | varchar(50) | NOT NULL | — | CHECK against `litigation, transactional, regulatory, employment, real_estate, estate_planning, other` |
| `description` | text | NULL | — | Work-product |
| `client_name` | varchar(255) | NOT NULL | — | Confidential; display convenience (structured data lives in `matter_parties`) |
| `deleted_at` | timestamptz | NULL | — | Soft delete; recoverable ≤ 30 days |
| `closed_at` | timestamptz | NULL | — | Set on CLOSED transition; cleared on reopen |
| `template_id` | uuid | NULL | — | **Phase 4 interface** — no FK yet |
| `template_version` | integer | NULL | — | **Phase 4 interface** |
| `status` | — | — | — | **DROPPED** per 006-D02 (supersedes 001-D03 for this column). Backfill: `'open'` → `lifecycle_state='INTAKE'` |

**Indexes:** keep `UNIQUE(org_id, matter_number)` and `org_id` index; add `CREATE INDEX matters_org_state ON matters (org_id, lifecycle_state) WHERE deleted_at IS NULL`; add trigram GIN: `CREATE INDEX matters_search_trgm ON matters USING gin (title gin_trgm_ops, client_name gin_trgm_ops, matter_number gin_trgm_ops)` (pg_trgm is installed).
**Constraints:** `CHECK (lifecycle_state IN ('INTAKE','ACTIVE','DISCOVERY','PRE_TRIAL','TRIAL_SETTLEMENT','CLOSED','RETENTION_HOLD','DISPOSITION'))`, `CHECK (matter_type IN (...))`.
**Retention behavior:** rows survive soft delete; hard delete only via Phase 5 disposition.
**Append-only?** No — but every mutation writes an `audit_events` row in the same transaction (DB trigger enforces audit immutability).

### `matter_parties` — CREATE

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid FK → organizations | NOT NULL | — | Tenant key |
| `matter_id` | uuid FK → matters | NOT NULL | — | Matter scoping |
| `party_type` | varchar(30) | NOT NULL | — | CHECK: `client, opposing_party, opposing_counsel, witness, expert, court, other` |
| `name` | varchar(255) | NOT NULL | — | Confidential |
| `role_description` | text | NULL | — | |
| `email` | varchar(255) | NULL | — | |
| `phone` | varchar(50) | NULL | — | |
| `address` | text | NULL | — | |
| `user_id` | uuid FK → users | NULL | — | Optional link to a platform user |
| `deleted_at` | timestamptz | NULL | — | Soft delete |

**Indexes:** `matter_id`, `org_id`. **Retention:** survives matter soft delete; hard delete via disposition only.

### `matter_comments` — CREATE

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid FK → organizations | NOT NULL | — | Tenant key |
| `matter_id` | uuid FK → matters | NOT NULL | — | Matter scoping |
| `parent_id` | uuid FK → matter_comments | NULL | — | One-level threading; enforced in service (parent must be top-level) |
| `author_id` | uuid FK → users | NOT NULL | — | |
| `body` | text | NOT NULL | — | Markdown; work-product |
| `edited_at` | timestamptz | NULL | — | Set on edit within 24h |
| `deleted_at` | timestamptz | NULL | — | Tombstone — row and author retained |

**Indexes:** `(matter_id, created_at)`, `org_id`. **Retention:** tombstones survive; hard delete via disposition only.

### `matter_links` — CREATE (MAT-17 related matters)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid FK → organizations | NOT NULL | — | Tenant key |
| `matter_id` | uuid FK → matters | NOT NULL | — | Canonical: `matter_id < related_matter_id` (service-enforced) |
| `related_matter_id` | uuid FK → matters | NOT NULL | — | |
| `link_type` | varchar(30) | NOT NULL | — | CHECK: `same_client, consolidated, appeal, companion, other` |
| `note` | text | NULL | — | |

**Constraints:** `CHECK (matter_id <> related_matter_id)`; `UNIQUE(org_id, matter_id, related_matter_id)`. **Indexes:** `matter_id`, `related_matter_id`.

### `matter_document_log` — CREATE (MAT-04 received/sent register)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid FK → organizations | NOT NULL | — | Tenant key |
| `matter_id` | uuid FK → matters | NOT NULL | — | Matter scoping |
| `direction` | varchar(10) | NOT NULL | — | CHECK: `received, sent` |
| `counterparty` | varchar(255) | NOT NULL | — | |
| `logged_at` | timestamptz | NOT NULL | — | The business date of the send/receipt |
| `method` | varchar(30) | NOT NULL | — | CHECK: `upload, email, share_link, integration` |
| `notes` | text | NULL | — | Immutable after insert |
| `annotations` | jsonb | NOT NULL | `'{}'` | Mutable annotations only |
| `created_at` | timestamptz | NOT NULL | `now()` | No `updated_at` — append-only by design |

**Indexes:** `matter_id`, `(matter_id, logged_at)`. **Retention:** immutable rows survive everything short of disposition. **Phase 3 interface:** Phase 3 may add nullable `document_id` FK.

### `matter_comment_reads` — CREATE (unread counts for MAT-16)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid FK → organizations | NOT NULL | — | Tenant key |
| `matter_id` | uuid FK → matters | NOT NULL | — | |
| `user_id` | uuid FK → users | NOT NULL | — | |
| `last_read_at` | timestamptz | NOT NULL | — | Updated when the user views the timeline |

**Constraints:** `UNIQUE(matter_id, user_id)`. Internal telemetry — never privileged.

### `matter_grants` — NO CHANGE

Assignment (MAT-07) reuses the existing table and roles (`owner`, `editor`, `viewer`, `outside_counsel`).

## Migration order

1. `2026_10_07_1xxxxx_alter_matters_lifecycle.php` — add columns, backfill `open→INTAKE`, drop `status`, add indexes + checks.
2. `2026_10_07_1xxxxx_create_matter_parties_table.php`
3. `2026_10_07_1xxxxx_create_matter_comments_table.php`
4. `2026_10_07_1xxxxx_create_matter_links_table.php`
5. `2026_10_07_1xxxxx_create_matter_document_log_table.php`
6. `2026_10_07_1xxxxx_create_matter_comment_reads_table.php`

Timestamps must sort after all `2026_10_07_02*` migrations. Migrations are never edited after merge.

## Rollback safety

All six roll back cleanly (`down()` drops what `up()` created; the matters ALTER restores the `status` column with backfilled `'open'` values). No destructive data migration beyond the `status`→`lifecycle_state` move, which is reversible from the backfill mapping. Destructive disposition flows are Phase 5, not here.
