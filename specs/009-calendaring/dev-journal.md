# 009 — Dev journal

## 2026-10-19 — Archaeology + Council + Plan (orchestrator)

- Baseline at branch point (`8b34ac3`): Pint PASS (280 files), PHPStan level 7 OK, suite 252 green (CI simulator at parent commit; this commit is docs-only above it).
- Worktree strategy: build in the sim clone (`/tmp/ci-sim`) on `feat/009-calendaring`; the live checkout `/opt/vision-law` never leaves `main`. Host gates run from the sim clone (its vendor carries the dev tools the production install lacks); the container simulator is the authoritative gate at batch boundaries.
- Archaeology headline: 006 authorization, the audit chain, the queue, and the 010 home all reuse cleanly. The one real gap is O-03 — 006 has **no** ethical wall (org_admin is implicit owner on every matter). Council resolved it as a recorded, default-off EXTEND (`matters.restricted`, 009-D04) rather than pretending the matrix had one.
- Golden dates in `04-fixtures.json` were hand-computed and then re-checked against the shipped GA pack (Thanksgiving **and** the day after are pack holidays — two goldens were corrected before any code existed; this is why fixtures come first).
- Split (009-D01): core tickets first, templates/engine second, both delivered this run.
