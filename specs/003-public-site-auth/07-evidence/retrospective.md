# 003 — Public site + auth pages — T-07 retrospective

## What went well

- **The mockup was the spec's anchor.** Building to a pixel-approved mockup
  with named CSS variables made T-01 (tokens) and T-02 (landing) mechanical:
  tokens moved 1:1 from `05-ui.md` into `app.css` `:root` + `@theme`, and the
  landing section map was a checklist, not a design decision.
- **Backend-out tickets held.** T-05's route wiring landed on the first pass
  because the contract fixed names (`login`, `register`), redirects
  (003-D02), and the Fortify endpoint contract (no view logic invented).
- **The [O] gate worked as designed.** 003-O1 (org-name field vs. backend
  reality) never got an answer from Ian, so T-04 built the DEFAULT — copy
  adjusted to what `CreateNewUser` actually does. No backend invented, no
  contract drift; the decision stands flagged for Ian's review.

## What the next feature should know

1. **`welcome.blade.php` is gone.** Deleted in T-02; its 002-D08 door
   exemptions died with it. T-07 removed the stale `WELCOME` exemption refs
   from `VisionUiTest.php` and `UI_Standards.md`; the only marketing
   exemption is now `public/` under door 4 (verified by the new
   `test_door_4_public_views_exempt_from_h1_rule` test).
2. **003-D01 is a scoped exception, not a precedent.** The six dark marketing
   tokens (`--vl-dark-0/1`, `--vl-neon/neon-deep`, `--vl-dark-text/mut`) apply
   to the landing's nav/hero/trust-strip/final-CTA/footer ONLY. Anything
   behind auth stays light-only; the hero uses tokens, not `dark:` variants.
3. **Fortify redirects are provisional (003-D02 [P]).** `login`/`logout` →
   `/`, `register` → `/login`. When the app home ships, this spec's redirect
   entries are superseded — do not extend them ad hoc.
4. **`FORTIFY_REGISTRATION=false` → GET `/register` 404.** Covered by test;
   registration availability rides the same feature flag as the POST route.
5. **"Request a demo" and "Forgot password?" are `#` placeholders.**
   Deliberate non-goals of this spec (demo CTA has no backend; password reset
   screens are a follow-up spec).
6. **Testimonial badge is load-bearing.** The "SYNTHETIC PLACEHOLDER — NOT A
   REAL QUOTE" badge is per the brief; removing it changes the claim the
   landing makes.
