# 006 — Matter model and lifecycle — Freeze retrospective

**Date:** 2026-10-07
**Branch:** `feat/006-matter-model` → merged to `main`
**Verdicts:** all 14 claims green (evidence: `07-evidence/c-01…c-14`)

What should change — or at least be decided — before the documents phase
(Phase 3).

## Process

1. **T-05 acceptance was never confirmed by its worker.** The T-05 commit
   message claimed a mockup-vs-built screenshot comparison, but the worker
   errored before delivering its final report, so freeze had to re-verify
   from scratch (11 UI tests + 24 headless-Chromium screenshots at
   1440px/390px — all states matched). Rule going forward: the acceptance
   checklist lives in the ticket-close note or the commit body, not in a
   report that may never arrive.
2. **No screenshot tooling in the repo.** Freeze improvised with a snap
   `chromium --headless` on the dev server + a throwaway dump test that
   rendered pages to static HTML (deleted after). If Phase 3 wants visual
   verification again, write the documented dump-and-shoot script first —
   don't re-improvise.
3. **`05-ui.md` still reads DRAFT** (the mockup-approval line is blank).
   The mockup was approved (Ian, 2026-10-07) and the approval is recorded in
   the T-05 commit message — but the spec doc itself never got its
   `[x]`. Close the loop in the doc, not just in git history.

## Code

4. **Two fixture loaders.** `FixtureLoader::load()` (001 fixtures) and
   `FixtureLoader::loadMatterFixtures()` (006 fixtures) coexist. Phase 3
   will add document fixtures — unify on one loader (or one loader with
   named sets) before the fixture zoo grows a third species.
5. **Owner-level logic lives in two places.** `MatterService` uses its own
   `OWNER_LEVEL_ROLES` constant; `MatterGrantController` (006-D17) derives
   owner-ranked names from `AccessControl::ROLE_RANK`. Both are correct
   today, but the next rank change must touch both. Prefer a single
   `AccessControl::ownerLevelRoles()` helper.
6. **`RequireMatterAccess` returns JSON for every denial**, including HTML
   requests — the Blade `matters/denied.blade.php` page exists and is
   screenshot-verified, but no route renders it yet (noted in the view's
   own comment). If Phase 3 wants the human denied page on HTML requests,
   that's a small content-negotiation ticket.
7. **Comment markdown is escaped, not rendered** (T-05). Real markdown
   rendering is a Phase 3+ decision with an XSS surface — decide then,
   don't drift into it.

## Product / spec hygiene

8. **Empty-state CTA gating** (`$canCreate` hides "New matter" for
   viewers) is correct per 006-D03 but isn't written in `05-ui.md` — add
   one line so the next UI pass doesn't "fix" it.
9. **MAT-23 anomaly still open** — the source references a MAT-23
   touchpoint, the RFI matrix has MAT-01–MAT-22 only. Noted in the brief
   (006-D09); still needs the product owner's eyes, not a builder's guess.
10. **Trigram search is green at 10k rows** (`test_matter_search_performance`
    passed, caught in the C-10 evidence file) — the pre-mortem's p95
    concern is answered for now, but re-run against production-like volume
    before promising latency.
11. **Restore is org-admin-only within 30 days** (C-01). Confirm with the
    product owner whether attorneys should self-serve restore — the current
    behavior is spec-correct, the question is product intent.
12. **Conflict UX is specced but not visually verified**: the second
    concurrent writer gets 409 with legal next states and the modal
    re-opens with refreshed state (05-ui.md §States). A browser-level test
    of that flow would close the loop.

## Decisions added at freeze

- **006-D17** — 001's `MatterGrantController` last-owner guard now uses the
  rank comparison (`roleSatisfies($role, 'matter_owner')`; count derived
  from `ROLE_RANK`) instead of strict `=== 'matter_owner'`, so the
  contract-named `'owner'` grant counts. Regression test
  `test_cannot_remove_last_contract_named_owner_grant` fails pre-fix,
  passes post-fix.
