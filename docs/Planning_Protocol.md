# Vision Law — Planning Protocol (per-feature)

**Status:** [D] Decided 2026-10-06 — the per-feature planning protocol for Vision Law,
complementary to `docs/Build_Protocol.md`.
**Applies to:** every feature that adds **a route, a migration, or a screen**.
Small fixes (copy tweak, one-line bug, styling nudge) run in lightweight mode:
brief + tickets + verdicts, nothing else.

---

## The two planning layers

- **System level** (`docs/`): decision-tagged standing law — blueprint, RFI matrix,
  domain model, security/tenancy, drafting architecture, AI governance, UI and
  testing standards. Changed only with a recorded decision.
- **Feature level** (`specs/<NNN>-<slug>/`): this protocol. One folder per feature,
  artifacts generated in order:

```text
00-brief.md → 01-archaeology.md → 02-schema-delta.md → 03-contract.md
→ 04-fixtures.json → 05-ui-mockup.html → 05-ui.md → 06-plan.md → decisions.md
```

**Testing is not a state — it is a thread through every state:** verdicts are
defined in the Brief, fixtures fix the test data in the Council artifacts, tickets
carry named tests as acceptance criteria, green runs gate Execution, and Freeze
finalizes matrix cells and architecture tests.

---

## The states (in order; no skipping)

### State 1 — Brief (`00-brief.md`)

- **Goal** — one sentence.
- **Claims** — capabilities as a table: `ID · description · Verdict · RFI anchor`,
  where the Verdict is the **binary test** that proves the claim. A claim isn't
  done until its verdict is green.
- **Governing decisions** — which system docs and [D]/[P]/[O] items apply.
  If a claim needs an [O] answer, stop: get the answer first. Open items are
  questions, not assumptions.
- **Security and audit** — actors, data classes (internal / confidential /
  privileged / work-product / externally shared), allow events, denial events,
  leak sentinels (restricted strings that must never appear in output).
- **Evidence** — fixtures, real examples, source requirements.
- **Pre-mortem** — what could break · what we're assuming · what we don't know yet.
- **Non-goals** — explicit exclusions.
- **Authorship** — the orchestrator drafts the Brief; the assigned agent reviews it
  and appends concerns (especially the pre-mortem); the product owner approves.
  The executing agent never writes its own Verdicts.
- **Approval gate** — DRAFT → APPROVED. No brief, no work.

### State 2 — Archaeology (`01-archaeology.md`)

Scan the codebase and docs for what already exists. Output a
**REUSE / EXTEND / NEW / CONFLICT** table for models, policies, services, routes,
components, and tests — plus conflicts and constraints. Skipping this is how
agents duplicate things that exist.

End the doc with a **files-to-touch table** (file · action · owner · reason) and a
**protected files** list the feature must not edit. Record the baseline:
test count, `pint --test`, `phpstan`, asset build. Decide up front whether
cleaning baseline debt is in scope; if not, do not fix it mid-feature.

### State 3 — Council artifacts (relay race; each inherits the previous)

| Artifact | File | Content |
| --- | --- | --- |
| Schema delta | `02-schema-delta.md` | Migrations/fields against `DOMAIN_MODEL.md`. Every matter-owned table names its matter key (plus the owning organization key). Accepted deltas fold back into the domain model at Freeze. |
| Contract | `03-contract.md` | Routes + requests + component properties/actions/events. **Every operation names its authorization rule and its audit event (allow and deny).** Every service method lists inputs and the validation error for each bad input. Views may request only what the contract exposes. |
| Fixtures | `04-fixtures.json` | Real-data fixtures **before any logic**: normal case plus adversarial cases — users from another organization, users with no matter relationship, restricted documents, stale versions, malformed inputs. **One definition only:** either the JSON is loaded by test helpers/seeders, or the helper is canonical and the spec points to it. Never both hand-written. |
| UI mockup | `05-ui-mockup.html` | Required for any feature adding or changing a screen. One self-contained static HTML file: every permitted role's view, every state (empty, loading, validation error, denied, conflict, success), realistic data volume, destructive-action confirmation, desktop and 390px layouts. Synthetic legal data only. |
| UI spec | `05-ui.md` | Maps mockup screens to Blade/Livewire components, plus deviations. New patterns go into `UI_Standards.md` first. |

**Mockup approval gate:** the product owner approves the mockup (DRAFT → APPROVED,
recorded in `05-ui.md`) **before** `06-plan.md` is written. After approval the
mockup is frozen: a UI change during build means editing the mockup and getting
re-approval first.

**Conflict check before leaving this state:** the UI asks nothing the contract
doesn't provide · every restricted field has a matter-scoped authorization rule and an audit event (allow and deny)
· new security rules are written as matrix cells for the testing strategy.

### State 4 — Plan (`06-plan.md`)

**Atomic tickets, backend-out** (migration → model/policy/service →
routes/components → views → integration). Each ticket: files, inputs,
dependencies, forbidden edits, and **acceptance criteria = its Verdict (named
tests)**. A ticket is done only when its verdict is green in isolation **and**
the architecture suite, Pint, and PHPStan pass. Do not start the next ticket on
a red gate.

### State 5 — Execution

Builders execute tickets in order, obeying the UI and testing standards.
Sub-agent prompts are **self-contained**: ticket text, referenced artifact
sections, paths to the standards, security reminders for touched data classes,
and "tests must be green." Sub-agents don't read this protocol; the orchestrator
injects what they need.

### State 6 — Freeze

**One home per fact.** Fold accepted schema deltas into the domain model ·
add new components to `UI_Standards.md` · add new security rules as matrix or
architecture tests · record build decisions in `decisions.md` · add
`dev-journal/domain-knowledge/` entries · leave a `dev-journal/progress/` entry.

---

## The Commandments

1. **Don't guess.** [O] items are questions, not answers.
2. **Design ≠ labor.** The builder never changes schema without a spec delta.
3. **Fixtures before logic.** No code handles data that has no fixture.
4. **Atomic tickets.** Each completable and testable in isolation.
5. **No completion without a green Verdict.** Never weaken an assertion to get green.
6. **Authorization and audit are part of every spec.** A feature touching
   restricted data ships its rules and matrix cells in the Council artifacts.
7. **Search before building framework features** — verify the documented
   Laravel 13 API, don't trust memory.
8. **Never touch the live database.** Workers run migrations and seeds only
   against dedicated test databases. Running migrate:fresh, migrate:refresh,
   or db:seed against the live vision_law database is forbidden.

## Lightweight mode

Small changes: a three-line brief (goal + claim + verdict), the work, and the
green test or screenshot. Everything else runs the full states.

## Living rule

Refinements are recorded here with a date. The protocol serves the system docs —
if a rule ever conflicts with a non-negotiable in `AGENTS.md`, `AGENTS.md` wins.
