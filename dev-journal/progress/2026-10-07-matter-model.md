# Matter model and lifecycle (spec 006) — 2026-10-07

> Progress entry for the spec 006 build (tickets T-01…T-06 + freeze) on
> `feat/006-matter-model`. Decisions live in
> `specs/006-matter-model/decisions.md` — linked, not duplicated.

## Done

- **T-01** schema/models/numbering: `matters` extended (`lifecycle_state`,
  `matter_type`, `client_name`, soft delete, `closed_at`,
  `template_id`/`template_version` reserved), `MAT-YYYY-NNNN` numbering via
  `pg_advisory_xact_lock` + MAX+1, `status` → `lifecycle_state` migration
  (006-D01, D02, D10). (C-01)
- **T-02** lifecycle state machine + MatterController: guarded transitions,
  409 with legal next states, same-state 200 no-op (006-D06), every
  transition audited, concurrency serialized. (C-02, C-03)
- **T-03+T-04** parties (7 types, 006-D14), comments (markdown, one-level
  threading, 24h edit, tombstones, @mentions), bidirectional typed
  matter links, append-only document log, assignment via `matter_grants`
  (006-D05), trigram search + structured filters, computed status summary.
  Contract roles reconciled in `ROLE_RANK` (`owner` ≡ `matter_owner`,
  006-D11); timestamptz(6) for deterministic unread counts (006-D15).
  (C-03…C-12)
- **T-05** Blade screens: index (search, 8 state chips, type/assignee
  filters, `x-ui.table`, empty states), show (Overview/Documents/Timeline/
  Team tabs), create/edit form, transition + delete modals, generic denied
  page. Content negotiation keeps the JSON contract intact. Freeze
  re-verified: 11 UI tests + 24 headless-Chromium screenshots (1440px +
  390px) across empty/validation/denied/modal states — all match the
  approved mockup; `x-ui.*` doors hold. (UI claims)
- **T-06** authorization matrix (all 006 routes × 8 actor classes, C-13) +
  activity feed as a read model over `audit_events` (006-D04, C-07).
  Contract action levels `:comment`/`:edit`/`:manage` landed; comment
  author-or-manage rule stays in the controller; `:update`/`:grant` kept
  as deprecated aliases; `MatterService::deleteMatter` owner check fixed
  to a rank comparison (006-D16).
- **Freeze** — 006-D17: the latent twin on 001's `MatterGrantController`
  (`activeOwnerGrantCount`/`isActiveOwnerGrant` used strict
  `=== 'matter_owner'`, ignoring contract-named `'owner'` grants) fixed
  with the same rank comparison; regression test
  `test_cannot_remove_last_contract_named_owner_grant` fails pre-fix,
  passes post-fix. All 14 verdicts green with per-claim evidence in
  `specs/006-matter-model/07-evidence/`. Schema deltas folded into
  `docs/PRODUCT_BLUEPRINT.md` §4.1; the `x-ui.status` matter map folded
  into `docs/UI_Standards.md`. Retrospective:
  `specs/006-matter-model/08-retrospective.md`.

## Gates (freeze, on the branch)

- `vendor/bin/pint --test`: PASS (152 files)
- `vendor/bin/phpstan analyse --no-progress`: OK, no errors (level 7)
- `php artisan test`: **186 passed / 2694 assertions**, 0 failures
  (the 2 known `RegistrationFlagTest` environment failures did not appear)
- `npm run build`: clean

## Merged

`git merge --no-ff feat/006-matter-model` → `main` ("Merge
feat/006-matter-model: matter model and lifecycle (006, all 14 verdicts
green)"), pushed; deploy poller picks it up automatically.
