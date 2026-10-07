# 001 — Foundation — T-07 retrospective

## What went well

- **The contract did its job.** The route table + error catalog in
  `03-contract.md` made the route walk mechanical: every route had a named
  authorization rule to check against, and the walk found zero violations.
  The `audit.viewer.denied` event name was already specified — no invention
  needed.
- **Earlier tickets left clean seams.** `RequireOrgAdmin` took an optional
  `$denialEvent` parameter with zero behavior change for existing routes;
  `RequireMatterAccess`'s middleware-first pattern was directly reusable as
  the model for the audit routes. The `FixtureLoader` + `loginAs` patterns
  made the viewer and sentinel tests fast to write.
- **Architecture tests as code, not prose.** Doors 1–3 are static scans over
  `app/` and the route collection — they will catch future regressions
  (e.g. a controller reaching for `Storage`, a direct `AuditEvent::create`,
  a new route missing `auth`) without anyone re-reading the spec.
- **The leak-sentinel test earned its keep on the first run.** Building
  sentinels from the real stores (decrypted TOTP secret, real password
  hash, live invitation token) is stronger than asserting on canned strings.

## What the next feature should know

1. **`object_type` is derived, not stored.** The viewer filters
   `object_type` by event-name prefix (`matter.*` → `matter`) because
   nothing in this feature populates `auditable_type`. If a later feature
   starts setting `auditable_type`, the controller already honors it — but
   the filter semantics (`event LIKE 'type.%'`) should be revisited then.
2. **`storage/{path}` is unauthenticated framework file-serving**
   (`FilesystemServiceProvider::serveFiles`, local disk). Safe today
   (nothing writes there — door 1), but the file-handling phase (PLT-23+)
   must address it deliberately; see observation 3 in `route-review.md`.
3. **`GET /` is a named exception** in the authenticated-by-default door
   (static welcome view, zero data access). If it ever gains data, the
   exception must be removed, not extended.
4. **Export writes `audit.exported` before streaming**, so the CSV never
   contains its own export event. The watermark (`exported-by`, timestamp,
   org, filters) is in both the CSV header and the audit payload.
5. **PHPStan is strict about model property nullability.** `auditable_id` /
   `matter_id` infer as non-nullable from the model; prefer truthiness or
   explicit null-handling over `!== null` comparisons on Eloquent
   attributes, and keep filter-shape docblocks honest about `|null`.
6. **`npm run build` needed a fresh `npm install`** on this checkout
   (`node_modules` was absent). CI installs it; local runs should too
   before trusting the gate.
7. **No Blade was built** (001-D06 binding). The viewer is JSON + CSV
   download; screen 9's Blade work waits for the approved mockup.
