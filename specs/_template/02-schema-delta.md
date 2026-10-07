# <NNN> — <Feature name> — Schema delta

**Status:** DRAFT (→ APPROVED)
**Author:** <name>
**Date:** <YYYY-MM-DD>
**Archaeology:** `01-archaeology.md`

> State the schema change against the domain model. If this feature needs no
> schema change, write "No schema change — <reason>" and keep the file. A
> missing file means "forgot", not "none".

---

## Tables

### <table_name> — <CREATE | ALTER>

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** — every tenant-owned table must identify its tenant key |
| `matter_id` | uuid, FK → matters | NOT NULL | — | Matter scoping |
| <...> | <...> | <...> | <...> | <...> |

**Indexes:** <list — every FK column indexed; query-driven composite indexes named>
**Constraints:** <unique, check, exclusion — e.g. `UNIQUE(matter_id, version_number)` for gapless versions>
**Encryption:** <per sensitive column — classification (internal/confidential/privileged/work-product) and mechanism, e.g. "envelope encryption via KMS; not searchable by plaintext">
**Retention behavior:** <what happens on matter close / retention hold / disposition — e.g. "rows survive soft delete; hard delete only via disposition flow">
**Append-only?** <yes/no — if yes, note the DB enforcement: REVOKE UPDATE/DELETE, trigger, or RLS policy>

<Repeat per table.>

## Migration order

1. `<timestamp>_create_<table>.php` — <what it creates>
2. `<...>` — <...>

<RULES:>
- Migrations are ordered and never edited after merge — new change = new migration.
- Every tenant-owned table identifies its tenant key.
- Every sensitive field identifies its classification and encryption/search implications.
- Foreign keys to tables owned by other features are listed as cross-feature dependencies.
- Accepted changes are folded into the domain model at Freeze (not during implementation).

## Rollback safety

<Can each migration roll back cleanly? `down()` behavior. Destructive migrations
(drop column/table) require explicit product-owner sign-off noted here.>
