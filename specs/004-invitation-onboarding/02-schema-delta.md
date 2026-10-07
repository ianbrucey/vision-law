# 004 — Invitation onboarding UI — Schema delta

**Status:** DRAFT
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Archaeology:** `01-archaeology.md`

---

No schema change — the `invitations` table already exists (migration `2026_10_07_020004_create_invitations_table.php`, verified present 2026-10-07) with `org_id`, `email`, `token_hash`, `role`, `matter_id` (nullable), `invited_by`, `expires_at`, `accepted_at`, `revoked_at`, and no `updated_at` (append-mostly). The `matters` table exists for the optional matter-scope select. This feature adds views and response handling only; no migration is created.
