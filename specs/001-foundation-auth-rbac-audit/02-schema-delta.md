# 001 — Foundation: authentication, RBAC, and audit logging — Schema delta

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-06
**Archaeology:** `01-archaeology.md`

> Conventions: UUID primary keys everywhere (`gen_random_uuid()`, requires
> `pgcrypto`). Every tenant-owned table carries `org_id` NOT NULL. Timestamps
> are `timestamptz`. Money/amounts: none in this feature.

---

## Tables

### `organizations` — CREATE

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `name` | varchar(255) | NOT NULL | — | Display name |
| `slug` | varchar(100) | NOT NULL | — | URL-safe, globally unique |
| `settings` | jsonb | NOT NULL | `'{}'` | Org-level flags (e.g. `registration_mode: invite_only|open`, `mfa_required`) |
| `created_at` / `updated_at` | timestamptz | NOT NULL | `now()` | |

**Indexes:** `UNIQUE(slug)`.
**Retention behavior:** orgs are never hard-deleted in v1; deactivation flag lives in `settings`.

### `users` — CREATE (replaces scaffold migration before merge)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** |
| `name` | varchar(255) | NOT NULL | — | |
| `email` | citext | NOT NULL | — | Case-insensitive; unique per org |
| `password` | varchar(255) | NOT NULL | — | Argon2id hash (memory 64 MB, time 3) — **privileged, never rendered/logged** |
| `email_verified_at` | timestamptz | NULL | — | |
| `two_factor_secret` | text | NULL | — | Encrypted at rest — **privileged** |
| `two_factor_recovery_codes` | text | NULL | — | Encrypted JSON array of hashed codes — **privileged** |
| `two_factor_confirmed_at` | timestamptz | NULL | — | Enrollment completes only after a valid code |
| `last_login_at` | timestamptz | NULL | — | |
| `deactivated_at` | timestamptz | NULL | — | Soft off-board; login refused when set |
| `remember_token` | varchar(100) | NULL | — | |
| `created_at` / `updated_at` | timestamptz | NOT NULL | `now()` | |

**Indexes:** `UNIQUE(org_id, email)`; `INDEX(org_id)`.
**Encryption:** `two_factor_secret`, `two_factor_recovery_codes` — Laravel encrypted casts (APP_KEY envelope); not searchable by plaintext.
**Retention behavior:** rows survive deactivation; hard delete only via a future disposition flow.

### `password_reset_tokens` — CREATE (Laravel default shape)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `email` | citext, PK | NOT NULL | — | |
| `token` | varchar(255) | NOT NULL | — | SHA-256 hash of the 256-bit token; plaintext only ever in the email |
| `created_at` | timestamptz | NULL | — | 1 h expiry enforced in code (PLT-03) |

### `sessions` — CREATE (Laravel database session table)

Standard Laravel `sessions` schema (`id` PK text, `user_id` uuid FK nullable, `ip_address`, `user_agent`, `payload` longtext, `last_activity` int). Powers PLT-08 session listing. **Leak note:** `payload` holds serialized session data — the session-list query selects only `id, ip_address, user_agent, last_activity`.

### `invitations` — CREATE

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** |
| `email` | citext | NOT NULL | — | Invitee address |
| `token_hash` | varchar(255) | NOT NULL | — | SHA-256 of 256-bit token; UNIQUE |
| `role` | varchar(50) | NOT NULL | — | Org role to assign on accept |
| `matter_id` | uuid, FK → matters | NULL | — | Optional matter-scoped invite (also creates a grant on accept) |
| `invited_by` | uuid, FK → users | NOT NULL | — | |
| `expires_at` | timestamptz | NOT NULL | `now() + interval '7 days'` | |
| `accepted_at` | timestamptz | NULL | — | |
| `revoked_at` | timestamptz | NULL | — | |
| `created_at` | timestamptz | NOT NULL | `now()` | |

**Indexes:** `UNIQUE(token_hash)`; `INDEX(org_id, email)`.
**Retention behavior:** nightly purge of expired+accepted/revoked rows older than 30 days (PLT-04).

### `teams` — CREATE, `team_user` — CREATE

`teams`: `id` uuid PK, `org_id` FK NOT NULL (**tenant key**), `name` varchar(255) NOT NULL, `created_at`/`updated_at`.
`team_user`: `team_id` FK, `user_id` FK, `created_at`; PK `(team_id, user_id)`.
**Indexes:** `UNIQUE(org_id, name)`; `INDEX(user_id)` on pivot.

### Spatie permission tables — CREATE (published, UUID-converted)

`permissions` (`id` uuid PK, `name`, `guard_name`), `roles` (`id` uuid PK, `name`, `guard_name`, `org_id` FK — roles are org-scoped), `model_has_permissions`, `model_has_roles`, `role_has_permissions` with `uuidMorphs` columns instead of the default integer morphs. Seeded permission list is the single source of truth (see `03-contract.md` §Permission matrix).

### `matters` — CREATE (minimal stub; EXTENDED by the matter-management feature)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** |
| `matter_number` | varchar(50) | NOT NULL | — | Human identifier, unique per org |
| `title` | varchar(500) | NOT NULL | — | **Confidential** — never leaks cross-org |
| `status` | varchar(30) | NOT NULL | `'open'` | `open|closed|archived` |
| `created_at` / `updated_at` | timestamptz | NOT NULL | `now()` | |

**Indexes:** `UNIQUE(org_id, matter_number)`; `INDEX(org_id)`.
This table exists here ONLY so matter-scoped authorization has something real to gate. Full matter lifecycle, parties, and documents arrive in the matter-management feature, which EXTENDs this table (new migration, never edits this one).

### `matter_grants` — CREATE

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** |
| `matter_id` | uuid, FK → matters | NOT NULL | — | Matter scoping |
| `user_id` | uuid, FK → users | NULL | — | Exactly one of `user_id`/`team_id` NOT NULL (CHECK) |
| `team_id` | uuid, FK → teams | NULL | — | |
| `role` | varchar(30) | NOT NULL | — | `matter_owner|matter_admin|editor|viewer` |
| `expires_at` | timestamptz | NULL | — | NULL = no expiry; expired rows deny automatically |
| `granted_by` | uuid, FK → users | NOT NULL | — | |
| `created_at` | timestamptz | NOT NULL | `now()` | |

**Indexes:** `UNIQUE(matter_id, user_id)` (partial, where `user_id IS NOT NULL`); `UNIQUE(matter_id, team_id)` (partial); `INDEX(matter_id)`; `INDEX(user_id)`.
**Constraints:** `CHECK ((user_id IS NULL) <> (team_id IS NULL))`.
**Retention behavior:** grant rows are never deleted — revocation sets `expires_at = now()` (history preserved for audit).

### `audit_events` — CREATE (append-only)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | uuid, PK | NOT NULL | `gen_random_uuid()` | |
| `org_id` | uuid, FK → organizations | NOT NULL | — | **Tenant key** |
| `actor_id` | uuid, FK → users | NULL | — | NULL = system action |
| `event` | varchar(100) | NOT NULL | — | e.g. `auth.login`, `matter.access.denied` |
| `auditable_type` | varchar(255) | NULL | — | Polymorphic subject |
| `auditable_id` | uuid | NULL | — | |
| `matter_id` | uuid, FK → matters | NULL | — | Denormalized for fast matter-scoped audit |
| `ip` | inet | NULL | — | |
| `user_agent` | text | NULL | — | |
| `payload` | jsonb | NOT NULL | `'{}'` | Event details — **never contains privileged data** (hashes, secrets, tokens) |
| `prev_hash` | char(64) | NOT NULL | — | Hash chain link |
| `row_hash` | char(64) | NOT NULL | — | `sha256(prev_hash \|\| canonical_json(payload) \|\| event \|\| actor_id \|\| created_at)` |
| `created_at` | timestamptz | NOT NULL | `now()` | No `updated_at` — append-only |

**Indexes:** `INDEX(org_id, created_at)`; `INDEX(org_id, event)`; `INDEX(matter_id, created_at)`; `INDEX(actor_id)`.
**Append-only enforcement:** migration ends with `REVOKE UPDATE, DELETE ON audit_events FROM <app_role>`; application code writes only through `AuditLogger`. `row_hash` chain verified by a nightly integrity job (job itself is a later feature; the chain fields are written now).
**Concurrency:** `prev_hash` is computed inside the insert transaction under a per-org advisory lock (`pg_advisory_xact_lock(hashtext(org_id::text))`) so concurrent writers cannot fork the chain.

## Migration order

1. `*_create_organizations_table.php`
2. `*_create_users_table.php` (replaces scaffold migration — allowed pre-merge)
3. `*_create_password_reset_tokens_table.php`
4. `*_create_sessions_table.php`
5. `*_create_invitations_table.php`
6. `*_create_teams_tables.php`
7. `*_create_permission_tables.php` (spatie, published + UUID-converted)
8. `*_create_matters_table.php` (minimal stub)
9. `*_create_matter_grants_table.php`
10. `*_create_audit_events_table.php` (ends with the REVOKE)

**RULES:** ordered, never edited after merge · every tenant-owned table identifies `org_id` · FKs to `matters` from this feature are the stub this feature creates (the matter feature EXTENDs, never edits).

## Rollback safety

All migrations roll back cleanly with `down()` dropping what `up()` created. The REVOKE in migration 10 is reverted in `down()` with a matching GRANT (dev-only path). No destructive data migrations in this feature.
