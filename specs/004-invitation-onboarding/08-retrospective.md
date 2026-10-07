# 004 — Invitation onboarding UI — Retrospective

**Date:** 2026-10-07
**What this changes before the next feature (matter model).**

## What worked

- **One-home-per-fact held under pressure.** The contract's forbidden list said
  "no new service methods," but 004-D01 sanctioned a narrow, reviewed addition.
  T-01 chose `inviteWithToken()` returning a request-scoped DTO (004-D06) with
  `invite()` delegating unchanged — validation, transaction, audit, and mail
  stayed in one place. The pattern works: let the contract name the narrow
  exception explicitly rather than forcing builders to smuggle one in.
- **Doors and leak sentinels scaled.** `VisionUiTest` doors held on the new
  views with zero new primitives; the 004-D07 holder exception was encoded
  as an amended sentinel assertion rather than a weakened test. Security
  assertions got *more precise*, not softer.
- **Zero-cost fold-back is the ideal.** No migration (table pre-existed), no new
  `<x-ui.*>` components, `UI_Standards.md` untouched. The 002 inventory was
  sufficient — the freeze reconciliation was five minutes of verification.
- **Journey tests caught the real bug.** The guest-middleware round-trip break
  (authenticated user bounced off the accept page) was caught by
  `test_existing_user_round_trip_sign_in_then_accept`, not by review or a
  happy-path test. Verdict tests that cross mid-flow state transitions earn
  their keep.

## What should change for the matter model

1. **Record decisions at decision time.** 004-D08 reached the freeze as an
   *uncommitted row* in `decisions.md` — it was decided in T-03 but written
   down late. D06/D07 were committed at decision time and that is the model.
   Rule: the commit that implements a decision also appends the decisions.md
   row. The freeze should never discover new decision debt.
2. **Contracts must enumerate actor states per route, not single auth labels.**
   D08 happened because the contract labeled `GET /invitations/{token}`
   "guest" while the flow legitimately involves signed-in users. The matter
   model will have far more mixed-actor surfaces (attorney, outside counsel,
   org admin, cross-org reads). The contract's auth column should spell out
   behavior for each relevant actor state — guest, signed-in non-member,
   member, admin, cross-org — rather than one shorthand word.
3. **Keep verdict tests journey-shaped.** The round-trip catch proves the
   pattern: for the matter model, verdict tests should include at least one
   full actor journey per claim (create → share → accept → act), including
   authentication state transitions between steps.
4. **Plan the schema fold-back explicitly.** 004 had no schema delta; the
   matter model will have the first real one. Make the fold-back a named
   ticket step: reconcile `02-schema-delta.md` against the final migrations
   (column names, nullability, indexes, append-mostly semantics) before the
   gates, not during.
5. **Seed the leak sentinels from T-01 for the new entity.** 004's sentinels
   were extended late (D07 amendment in T-04). For matter model, the first
   implementation ticket should add sentinel coverage for the entity's
   privileged fields (matter identifiers, client names, privileged flags)
   while the scan surface is still small.
6. **Resolve mail transport before relying on mail.** 004 correctly fell back
   to the copy-paste link with a visible "email not configured" banner
   (004-D03). Matter-model sharing flows will lean harder on mail — either
   configure transport or carry the banner pattern forward deliberately, not
   by accident.
