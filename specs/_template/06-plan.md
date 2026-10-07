# <NNN> — <Feature name> — Implementation plan

**Status:** DRAFT (→ APPROVED)
**Author:** <orchestrator name>
**Date:** <YYYY-MM-DD>
**Contract:** `03-contract.md` · **Fixtures:** `04-fixtures.json` · **UI:** `05-ui.md`

> Atomic, backend-out tickets: baseline → migration → model/policy/service →
> transport/events/jobs → UI → integration → evidence/freeze. Prefer a few
> independently testable tickets over many tiny tickets that cannot stand
> alone. Parallel workers get disjoint file ownership (see 01-archaeology.md).

---

## Ticket 1 — <Outcome, e.g. "Migration and models for version restore">

**Files:** <owned files — must match the files-to-touch table>
**Inputs:** 02-schema-delta.md §<tables>, 04-fixtures.json cases <ids>
**Dependencies:** none (baseline ticket)
**Forbidden edits:** <protected files from 01-archaeology.md>

### Work
- [ ] <implementation step>
- [ ] <implementation step>

### Acceptance criteria (binary verdicts from 00-brief.md)
- [ ] <C-01 verdict> — <named test>
- [ ] Architecture suite green (`php artisan test tests/Architecture`)
- [ ] Pint green (`vendor/bin/pint --test`)

---

## Ticket 2 — <Outcome, e.g. "Restore endpoint + authorization + audit">

**Files:** <owned files>
**Inputs:** 03-contract.md §Routes, §Error catalog, §Audit events
**Dependencies:** Ticket 1
**Forbidden edits:** <protected files>

### Work
- [ ] <implementation step>
- [ ] <implementation step>

### Acceptance criteria
- [ ] <C-02 verdict> — <named test>
- [ ] Authorization matrix cells for this endpoint × {owner, editor, viewer, outside_counsel, unassigned, anonymous} assert expected 200/403/404
- [ ] Leak sentinels: restricted strings absent from HTML/JSON/logs for denied actors
- [ ] Architecture suite + Pint green

---

## Ticket 3 — <Outcome, e.g. "UI: version history and restore dialog">

**Files:** <owned files>
**Inputs:** 05-ui.md §<screen>, approved `05-ui-mockup.html`
**Dependencies:** Ticket 2
**Forbidden edits:** <protected files>

### Work
- [ ] <implementation step>

### Acceptance criteria
- [ ] <UI verdicts> — <named browser/component tests>
- [ ] Empty/loading/validation/denied/conflict/success states render per 05-ui.md
- [ ] `npm run build` succeeds

---

## Ticket 4 — Evidence & freeze

**Files:** `07-evidence/`, `08-retrospective.md`, `decisions.md`, domain model updates
**Dependencies:** Tickets 1–3

### Work
- [ ] Full suite green: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`
- [ ] Verdict output for every claim in 00-brief.md saved under `07-evidence/`
- [ ] Accepted schema changes folded into the domain model
- [ ] New UI primitives added to UI standards; new security rules added as architecture/matrix tests
- [ ] Decisions made during execution recorded in `decisions.md`
- [ ] `08-retrospective.md` written: what should change before the next feature

### Acceptance criteria
- [ ] All brief verdicts green with evidence linked
- [ ] CI green on the feature branch; independent review approved; merged per the review protocol

---

<RULES:>
- A ticket is complete only when its own verdict AND the standing architecture/style gates are green.
- A failing test is fixed at the cause — assertions are never weakened to get green; if a governing decision changed, update the decision first, then the test.
- Stop on contract drift: if implementation reveals a contract error, update and re-approve the artifact before continuing.
- No opportunistic cleanup: baseline debt is either scoped into the brief or left alone.
