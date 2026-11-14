# 013 — Vision Systems UI refactor & generic landing page — Schema delta

**Status:** DRAFT (→ APPROVED)
**Author:** Zuri (orchestrator)
**Date:** 2026-11-14
**Archaeology:** `01-archaeology.md`

---

## No schema change

**No schema change — this feature is presentation-only.**

Rationale:

- The 25 themes are **static token sets shipped as CSS** (design tokens
  defined once in the stylesheet layer, per brief C-02). A theme is data
  the build ships, not data a user creates; it belongs in the token
  contract (`03-contract.md`), not in a table.
- Theme **selection** is client-side presentation state: a data attribute
  on the root element, swapped by the switcher without a reload. Nothing
  about it is matter data, org data, or audit data, and no other actor
  ever needs to read it.
- The landing page is read-only and anonymous-safe (spec 003 C-04 stands);
  it reads no tenant data at all.
- The slice-2 shell refactor changes how existing data is *rendered*,
  never what is stored. Every datum the shell and home show already
  exists (spec 010 archaeology established this for the home).

## The one possible exception — an open question, not an assumption

**Persisting a user's theme choice across devices** would need storage —
for example a nullable `ui_theme` column on `users`, or a key in a
preferences JSON column. That is recorded as open item **013-O06** in
`decisions.md` and is **not** assumed anywhere in this spec:

- The contract (`03-contract.md`) defines theme switching as client-side
  only.
- No ticket in `06-plan.md` adds a column, a table, or a settings write.
- If Ian decides persistence is wanted, it arrives as a schema delta
  amendment to this file (new migration, tenant/user key identified,
  rollback stated) and is re-approved before any builder touches it —
  per Commandment 2: the builder never changes schema without a spec
  delta.

## Migration order

None. There are no migrations in this feature.

## Rollback safety

Nothing to roll back at the data layer. Code rollback is a revert of the
feature merge (see `00-brief.md`, Production impact & rollback).
