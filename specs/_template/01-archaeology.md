# <NNN> — <Feature name> — Repository archaeology

**Status:** DRAFT (→ APPROVED)
**Author:** <builder or orchestrator name>
**Date:** <YYYY-MM-DD>
**Brief:** `00-brief.md` (must be APPROVED before this is written)

> Answer "what already exists?" before proposing anything new. Read the code —
> do not guess from memory. Check the version-matched framework docs for any
> API you plan to use; record the versions from the actual lockfiles.

---

## Findings

| Thing (code, migration, test, doc, config) | Location | Verdict | Constraint / note |
|---|---|---|---|
| <e.g. `DocumentVersion` model> | `app/Models/DocumentVersion.php` | REUSE | <use as-is; no changes> |
| <e.g. `DocumentStore::put()`> | `app/Services/DocumentStore.php` | EXTEND | <add `putVersion()`; keep interface BC> |
| <e.g. version diff renderer> | — | NEW | <no existing diff code found> |
| <e.g. legacy `FileHelper::save()>` | `app/Support/FileHelper.php` | CONFLICT | <bypasses DocumentStore — must not be used; do not refactor here> |

**Verdict meanings:**
- **REUSE** — use as-is; the feature may call it but not change it.
- **EXTEND** — build on it; changes must keep backward compatibility and be listed in Files to touch.
- **NEW** — does not exist; will be created by this feature.
- **CONFLICT** — exists but contradicts this feature's contract or an architecture law; do not use, do not silently refactor — either scope the fix into the brief or leave it alone and note it.

## Architectural doors this feature must use

<Name the standing boundaries, e.g.:>
- All file operations go through `DocumentStore` — controllers/Livewire never touch storage directly.
- All matter access goes through the centralized authorization check (`require_matter_access`) before any data access.
- Audit events are written only through `AuditLogger`.
- <...>

## Files to touch

| File | Action (create/edit) | Owner (worker) | Reason |
|---|---|---|---|
| <path> | <create/edit> | <worker name> | <why this file, what changes> |

<RULE: parallel workers must have disjoint file ownership. Shared files
(routes, service-provider bindings, shared layouts, core enums, migration
ordering) belong to the orchestrator unless explicitly delegated.>

## Protected files

<Paths this feature must NOT edit, and why — e.g. `routes/api.php` (orchestrator-owned),
`app/Policies/*` (owned by the identity feature), migrations from other features.>

## Baseline

<Record the actual state BEFORE this feature's work begins:>

- Tests: <e.g. `php artisan test` — 142 passed, 0 failed>
- Architecture tests: <e.g. `php artisan test tests/Architecture` — green>
- Pint: <e.g. `vendor/bin/pint --test` — clean>
- PHPStan: <e.g. `vendor/bin/phpstan analyse` — no errors>
- Asset build: <e.g. `npm run build` — success>
- Framework/package versions checked: <e.g. laravel/framework 13.x from composer.lock; Livewire docs v4>

<If the baseline is red, stop and report — do not build on a broken baseline.>
