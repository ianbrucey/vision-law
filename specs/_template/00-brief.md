# <NNN> — <Feature name> — Strategic brief

**Status:** DRAFT (→ IN REVIEW → APPROVED)
**Author:** <orchestrator name>
**Date:** <YYYY-MM-DD>

> HOW TO USE THIS TEMPLATE: copy this whole `specs/_template/` folder to
> `specs/<NNN>-<feature-slug>/` and fill in every `<...>` placeholder. Do not
> delete sections — if a section does not apply, write "N/A — <reason>" so the
> omission is explicit. No implementation work begins until this brief is
> APPROVED by the product owner.

---

## Goal

<One sentence: the user outcome. Not a feature name — e.g. "An attorney can
restore any prior version of a matter document with its full history intact."
If you cannot write the outcome in one sentence, the scope is too big — split
the feature.>

## Claims

Every capability gets a named, binary verdict. The verdict is written by the
planner/orchestrator — **a builder never writes the verdict that will judge
its own work** — and the builder may challenge verdicts before approval.

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | <what the user can do> | <e.g. "test_restore_creates_new_version_with_old_bytes" — PASS/FAIL, no judgment calls> | <e.g. DOC-20, or "—" if none> |
| C-02 | <...> | <...> | <...> |

<RULE: every claim that touches an RFI requirement MUST name the matrix ID
from docs/RFI_REQUIREMENTS_MATRIX.md. Claims without an RFI anchor must say
why they exist (tech debt, security hardening, UX necessity).>

## Blueprint anchors

Link each claim to the governing system decisions. Copy the tags exactly.

- [D] <e.g. D-04 server-side matter-level authorization — claim C-01's endpoint calls require_matter_access before any data access>
- [P] <proposed decision this feature relies on — flag that it is not implementation authority>
- [O] <open question this feature depends on> → **stop condition:** <what the builder must do instead of guessing — e.g. "defer section-locking UI; implement explicit conflict resolution only">

<RULE: if any [O] item is load-bearing for this feature, the brief must state
the stop condition. Builders stop on unresolved [O] items; they do not invent
answers.>

## Security classification

- **Actors:** <e.g. org_admin, attorney, paralegal, outside_counsel, viewer, anonymous>
- **Data classes touched:** <internal | confidential | privileged | work-product | externally shared>
- **Allow events (audit):** <e.g. document.version.restored>
- **Denial events (audit):** <e.g. document.version.restore.denied>
- **Leak sentinels:** <restricted strings that must never appear in HTML/JSON/mail/queue payloads/logs/exports/error messages for unauthorized actors — e.g. "opposing counsel strategy memo title">

## Evidence and fixtures

<Canonical test scenario: the synthetic matter, users, documents, and
adversarial cases this feature builds against. Point to 04-fixtures.json.
Include cross-tenant users, unauthorized matter users, and restricted
documents where relevant.>

## Pre-mortem

- **Likely failure:** <what will probably go wrong first>
- **Assumption:** <what we are assuming that might be false>
- **Unknown:** <what we don't know yet>
- **Mitigation:** <what we do about each of the above>

## Non-goals

<Explicit exclusions — e.g. "Real-time co-editing is out of scope; section
versioning is the v1 safety net." If it is not listed here or in Claims, it
is not being built.>

## Approval gate

- [ ] Orchestrator verdicts written (claims table complete)
- [ ] Builder has reviewed and added risks to the pre-mortem
- [ ] Governing [D]/[P]/[O] items linked; [O] stop conditions stated
- [ ] Security classification complete (actors, data classes, audit events, leak sentinels)
- [ ] Product owner approved — **approver:** <name> · **date:** <YYYY-MM-DD>

<No code, no schema changes, no tickets until every box is checked.>
