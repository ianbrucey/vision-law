# 003 — Public site + auth pages — Schema delta

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Archaeology:** `01-archaeology.md`

> State the schema change against the domain model. If this feature needs no
> schema change, write "No schema change — <reason>" and keep the file. A
> missing file means "forgot", not "none".

---

## No schema change

**No schema change — this spec is presentation + view-route wiring only.**

- The auth tables this spec exercises (`users`, `organizations`, `invitations`, `roles`, `model_has_roles`) already exist from spec 001; registration reuses the 001 `CreateNewUser` action and its transaction boundary (user-create + role-assign + audit commit atomically) with zero new columns.
- The landing, login, and register pages are static/guest surfaces: they read no database state and write nothing directly. All writes flow through the existing 001 POST endpoints (`/login`, `/register`).
- No new models, no new relations, no new indexes, no migration.

## Migration order

N/A — no migrations.

## Rollback safety

N/A — no migrations.
