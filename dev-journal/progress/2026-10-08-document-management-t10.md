# 007 T-10 — audit trail + mobile gate + freeze — 2026-10-08

> T-10 ticket on `feat/007-document-management`. Verdicts: `test_document_audit`
> (C-14), `test_mobile_layout` (C-15). Decisions live in
> `specs/007-document-management/decisions.md` — linked, not duplicated.

## Done

- **Audit wiring review**: walked every ticket's `AuditLogger::log` call sites
  against the brief's allow/denial catalog. Exactly-once holds per action:
  `document.uploaded` (ingest, single + chunked), `document.previewed`
  (preview page), `document.downloaded` (original / text-layer / bulk-ZIP are
  distinct actions), `document.version.restored` (+ `version.created` for the
  N+1 row — the T-07-tested convention), `document.shared` (grant vs link are
  distinct), `document.destroyed` (007-D07 purge path). Kept the T-03
  unconditional `document.previewed` as a conservative superset of the
  contract's flagged-only qualifier (007-D14).
- **Per-document Activity tab** (`documents.activity`) + **matter-level CSV
  export** (`documents.activity.export`): read models over `audit_events`
  (006-D04, no new table), `:view` + `DocumentAccess` `:view`, export
  whitelists `document.*`/`template.*`/`folder.*` oldest-first (007-D16).
- **`test_document_audit`** (C-14): upload→preview→download→restore→share each
  writes exactly one row for its cataloged event; Activity tab renders the
  chain; CSV contains it; ungranted actor gets 404 on both surfaces.
- **Integration fixes** (freeze-flag items):
  - Duplicate casts: only `app/Casts/PostgresTextArray.php` exists —
    `app/Support/PgTextArray.php` was never in the tree; no change needed.
  - `FixtureLoader::loadDocumentFixtures()` `range(2, 1)` bug: already fixed
    in-tree (plain `for` loop with an explanatory NOTE); verified, no change.
  - `DocumentAccess` wired into T-03's preview page + signed byte routes
    (replaces the matter-only check; denials audit `document.access.denied`).
    T-06 search verified as DocumentAccess-equivalent at the HTTP layer —
    `Matter::accessibleBy` admits exactly the `can(view)` rows, so no per-row
    check (would be dead code + N+1) (007-D15).
  - `DocumentStore::archive()` added (interface + `LocalDocumentStore`);
    `RetentionService::archiveBlob`'s physical move moved there; the service
    now calls `app(DocumentStore::class)->archive($blob)`.
  - Preview disabled for archived blobs (one-line check in
    `resolvePreviewTarget` → graceful "preview unavailable").
  - Hold banner on the preview page (active document + matter holds, `warn`
    tone) + per-document section tabs (Preview/Versions/Share/Activity).
  - T-02 riders verified intact: `require share.php`, template routes,
    `Document.php` template/draft hunks + text[] cast.
  - 007-D02 amended: poppler-utils (`pdftotext`) + libtiff-tools (`tiff2pdf`)
    recorded in provisioning notes (both verified installed).
- **Upload view built** (`documents/create.blade.php` + `resources/js/document-upload.js`,
  GET `documents.upload.form`): the mockup §02 screen never existed — only the
  POST endpoint did, and the index "Add documents" button 405'd. One flow for
  single-shot (XHR progress) and chunked (8 MiB PUTs, resume bitmap, sliced
  incremental SHA-256, 007-D05) (007-D17).
- **Mobile gate** (C-15, 007-D04): `test_mobile_layout` renders 11 screens at
  390px in headless Chromium (snap; stages under `/root/vl-mobile-test/`):
  no page overflow, primary actions ≥44px, tables scroll in cards. Screenshots
  in `specs/007-document-management/10-mobile-*.png`. Fixed along the way:
  9 undersized `size="sm"` row actions → `min-h-[44px]`; nav drawer toggles
  36px → 44px (002 layout, gate-mandated); `sr-only` span leaking 460px of
  swipeable whitespace on 4 tables (`relative` on the TH); "Thumbnails"
  button `shrink-0`; template generate view crashing on labelless fields.
- **Pre-existing red doors fixed at freeze** (both red at the base commit):
  AuthenticatedByDefault on T-08's share links → guest-listed with 007-contract
  citation (007-D19); door-3 on T-03's preview toolbar inputs → recorded
  exemption (007-D18).
- **Door-3 conformance**: the upload view's sr-only file picker goes through
  `<x-ui.field>` (raw control lives in the exempt `components/ui/`); the
  preview toolbar's pre-existing page/find inputs got a recorded
  `UI_Standards.md` exemption + scanner carve-out (007-D18) — `x-ui.field`
  cannot serve inline 44px toolbar widgets.
- **Docs**: 4 UI patterns proposed to `docs/UI_Standards.md` (dropzone,
  preview toolbar, version timeline, document section tabs); `05-ui.md`
  approval loop closed (Ian 2026-10-08); decisions 007-D02a/D14–D17 recorded.

## Verdicts

- `test_document_audit` — PASS (1 test, 33 assertions).
- `test_mobile_layout` — PASS (1 test, 111 assertions; 11 screens).

## Gates

- `vendor/bin/pint --test` — clean.
- `vendor/bin/phpstan analyse --no-progress` — level 7, zero errors.
- Full suite on `vision_law_test_t10` — green.
- `npm run build` — clean (includes new `document-upload.js` entry).
