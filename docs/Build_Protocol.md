# Legal application build protocol

**Status:** Proposed for approval  
**Extracted from:** Haven repository, `main` at commit `edd83fc`  
**Repository state inspected:** Clean working tree on October 6, 2026  
**Purpose:** Carry Haven’s agent-first build discipline into the new legal application without copying Haven’s foster-care domain rules.

## Decision summary

The Haven repository is accessible on the shared development server, and its build protocol is mature enough to reuse. The reusable part is not Haven’s data model or feature list; it is the way requirements become approved, testable, conflict-resistant work.

The legal application should adopt this sequence:

> system decisions → approved feature brief → repository archaeology → schema/contract/fixtures/UI artifacts → atomic tickets → isolated execution → independent review → green CI → dev deployment → freeze and record lessons

The most important rule is: **agents do not begin from a conversation or a feature name. They begin from an approved contract with binary verdicts.**

This document is a proposed protocol, not approval to start every feature. The drafting source of truth, repository model, tenancy boundary, and AI execution model remain architecture decisions that must be settled before drafting work is distributed.

---

## 1. What to copy from Haven

### Adopt unchanged

- **No brief, no work.** Every meaningful feature starts with an approved strategic brief.
- **Claims have binary verdicts.** Each capability is paired with a named test or other yes/no proof.
- **Archaeology before design.** Agents classify existing code as REUSE, EXTEND, NEW, or CONFLICT before proposing implementation.
- **Contracts before code.** Routes, service interfaces, data fields, authorization requirements, error behavior, events, and outputs are settled before tickets.
- **Fixtures before logic.** Test scenarios exist before implementation so agents build against the same facts.
- **Backend-out tickets.** Schema and domain seams precede transport, UI, and integration work.
- **Tests ship with each ticket.** A feature is not complete because it runs; it is complete when its verdicts are green.
- **Architecture laws are executable.** Critical boundaries are enforced by tests, not prose alone.
- **One home per fact.** Decisions, schema, contracts, and lessons each have a canonical location.
- **Complex bug fixes require a guard.** Every significant fix ends with a regression test, architecture test, or recorded rule.
- **Parallel work requires disjoint ownership.** Shared files and integration points belong to an orchestrator.

### Adapt for the legal domain

Replace Haven’s disclosure tiers and referral states with legal-specific controls:

- organization and tenant boundaries;
- matter membership and ethical walls;
- internal, confidential, privileged, work-product, and externally shared data classes;
- role and matter relationship;
- document version, legal hold, retention, and disposition state;
- drafting approval and release state;
- court-rule provenance and effective dates;
- AI tool permissions and human-approval requirements.

### Do not copy

- Haven’s entities, roles, fixtures, UI branding, disclosure rules, or roadmap;
- assumptions that self-registration is allowed;
- a package or pattern merely because Haven uses it;
- any feature-specific decision without confirming it against the legal application’s RFI and architecture decisions.

---

## 2. Two planning layers

### System layer: standing law

The repository should have a small set of authoritative documents under `docs/`:

```text
docs/
  PRODUCT_BLUEPRINT.md
  RFI_REQUIREMENTS_MATRIX.md
  DOMAIN_MODEL.md
  SECURITY_AND_TENANCY.md
  DRAFTING_ARCHITECTURE.md
  AI_GOVERNANCE.md
  UI_STANDARDS.md
  TESTING_STRATEGY.md
  PLANNING_PROTOCOL.md
  DEV_WORKFLOW.md
  ROADMAP.md
```

Each decision should be tagged:

- **[D] Decided** — agents may build against it.
- **[P] Proposed** — available for review, not implementation authority.
- **[O] Open** — work that depends on it stops or explicitly defers it.

### Feature layer: one folder per capability

```text
specs/<NNN>-<feature-slug>/
  00-brief.md
  01-archaeology.md
  02-schema-delta.md
  03-contract.md
  04-fixtures.json
  05-ui-mockup.html
  05-ui.md
  06-plan.md
  decisions.md
  07-evidence/
  08-retrospective.md
```

Not every feature changes every layer. A no-schema feature can mark the schema delta “none” with reasons. A backend-only feature can mark UI artifacts “not applicable.” Files remain present so omissions are explicit rather than accidental.

---

## 3. Feature lifecycle

### State 1 — Strategic brief

The orchestrator drafts `00-brief.md`. The builder reviews it and adds risks; the product owner approves it.

Required sections:

1. **Goal:** one sentence describing the user outcome.
2. **Claims:** capability, verdict, and RFI anchor.
3. **Governing decisions:** links to system documents and relevant [D]/[P]/[O] items.
4. **Security classification:** affected data classes, actors, and required audit events.
5. **Evidence:** fixtures, known examples, and source requirements.
6. **Pre-mortem:** likely failure, assumption, unknown, and mitigation.
7. **Non-goals:** explicit exclusions.
8. **Approval:** status, approver, and date.

A builder never writes the verdict that will judge its own work. The planner owns verdicts; the builder may challenge them before approval.

### State 2 — Repository archaeology

`01-archaeology.md` answers:

- What already exists?
- What is reusable, extendable, new, or conflicting?
- Which architectural doors must the feature use?
- Which files will change, and who owns them?
- What is the baseline test, style, static-analysis, and build status?
- Which version-matched framework documentation was checked?

The artifact ends with a **files-to-touch table** and a list of protected files the feature must not edit.

### State 3 — Council artifacts

These are produced in order because each constrains the next.

#### Schema delta

`02-schema-delta.md` states tables, fields, indexes, foreign keys, constraints, encryption, tenant keys, retention behavior, and migration order against `DOMAIN_MODEL.md`.

Every tenant-owned table must identify its tenant key. Every sensitive field must identify its classification and encryption/search implications. Accepted changes are folded into the domain model during Freeze.

#### Contract

`03-contract.md` defines:

- domain service methods and DTOs;
- routes, requests, Livewire properties/actions, events, and jobs;
- input validation and the expected error for every invalid input;
- authorization rule for every operation;
- audit event for allow, deny, read, mutation, export, and deletion where applicable;
- idempotency and transaction boundary;
- data returned to each role and data that must never appear;
- external integration failure behavior;
- AI tool permissions and human-approval gate, if AI is involved.

Views and agents may request only what the contract exposes.

#### Fixtures

`04-fixtures.json` contains one canonical scenario for normal operation plus adversarial cases. Fixtures must include cross-tenant users, unauthorized matter users, restricted documents, stale versions, failed integrations, and malformed inputs when relevant.

Use one definition only: either the JSON is loaded by test helpers/seeders or the code fixture is canonical and the spec points to it. Do not maintain two hand-written copies.

#### UI mockup and UI specification

A feature that adds or changes a screen requires a self-contained `05-ui-mockup.html` showing:

- each permitted role’s view;
- empty, loading, validation, denied, conflict, and success states;
- realistic data volume;
- destructive-action confirmation;
- desktop and 390-pixel layouts;
- redacted or synthetic legal data only.

`05-ui.md` maps mockup sections to Blade/Livewire components and records any approved deviation. The product owner approves the mockup before ticket planning. After approval, UI changes require an updated mockup and renewed approval.

### State 4 — Implementation plan

`06-plan.md` divides the work into atomic, backend-out tickets:

> baseline → migration → model/policy/service → transport/events/jobs → UI → integration → evidence/freeze

Each ticket names files, inputs, dependencies, acceptance tests, and forbidden edits. A ticket is complete only when its own verdict and the standing architecture/style gates are green.

Prefer a few independently testable tickets over many tiny tickets that cannot stand alone.

### State 5 — Execution

Builders execute tickets in order. Each agent prompt must be self-contained and include:

- the ticket text;
- relevant contract and fixture sections;
- applicable standards;
- file ownership and forbidden files;
- required tests and commands;
- security reminders for affected data;
- the instruction to stop on an unresolved [O] item.

Agents do not receive vague assignments such as “build document management.”

### State 6 — Review, merge, and deployment

- Work occurs in `feat/<NNN>-<slug>` branches and separate worktrees.
- The builder does not approve its own merge.
- CI runs on PostgreSQL and must pass tests, formatting, static analysis, and asset build.
- Merge occurs only after required checks and review are green.
- Merge to `main` deploys to the development environment.
- The dev environment uses synthetic data only.
- A smoke test confirms login and the feature’s critical path.
- Human review checks layout, workflow, and any output whose quality is not reducible to assertions.

### State 7 — Freeze

At Freeze:

- accepted schema changes move into `DOMAIN_MODEL.md`;
- new UI primitives move into `UI_STANDARDS.md`;
- new security rules become architecture or matrix tests;
- decisions made during execution go into `decisions.md`;
- complex lessons go into the dev journal;
- `07-evidence/` contains verdict output and approved screenshots;
- `08-retrospective.md` records what should change before the next feature.

One fact must have one canonical home. Freeze should link rather than duplicate.

---

## 4. Standing gates

### Security matrix

The legal application’s equivalent of Haven’s disclosure matrix should be a dataset-driven test over:

> actor role × tenant × matter relationship × data class × matter state × action

Each cell asserts:

- allow or deny;
- expected HTTP behavior without existence leakage;
- expected audit event;
- absence of restricted seeded strings in HTML, JSON, mail, queue payloads, logs, exports, and error messages.

Baseline actors should include organization administrator, attorney, paralegal/staff, client or external collaborator, system operator, other-tenant user, and guest. Final role names remain an architecture decision.

### Architecture laws

The first architecture tests should enforce these proposed doors:

1. **Tenant and matter authorization:** one policy layer determines matter access.
2. **Audit:** application code writes audit events only through `AuditLogger`.
3. **Documents:** controllers and Livewire components never call storage directly; all file operations use `DocumentStore`.
4. **Drafts:** UI code never mutates draft persistence directly; all changes pass through the approved drafting service.
5. **Deadlines:** no deadline calculation outside the rules engine.
6. **AI:** model providers are called only through the AI gateway; privileged tools require recorded human approval.
7. **Exports:** PDF/DOCX generation goes through the renderer/export service and is audited.
8. **Routes:** authenticated-by-default, with explicit public exceptions.
9. **Tenant keys:** tenant-owned records cannot be queried through unscoped repository paths.
10. **Secrets and protected text:** no client notification, log, or queued payload carries privileged document text unless its contract explicitly permits it.

Names are proposed; the architecture decision documents must finalize interfaces before tests lock them.

### Quality gates

For every ticket:

```bash
php artisan test tests/Architecture
vendor/bin/pint --test
```

For every feature and before merge:

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run build
```

CI should use the production database family, PostgreSQL, rather than SQLite. A failing test is fixed at the cause. An assertion is never weakened merely to obtain green status; if a governing decision changed, update the decision first and then the test.

---

## 5. Testing strategy for the legal application

### Layer 1 — Pure unit tests

Policy decisions, deadline math, business-day and holiday calculations, retention calculations, draft state transitions, clause-selection rules, and deterministic AI-tool argument validation.

### Layer 2 — Service and integration tests

Document storage/versioning, audit writes, legal holds, retention/disposition, draft revisions, rendering, search indexing, notifications, external sharing, and provider adapters.

### Layer 3 — HTTP feature tests

Every route: happy path, validation, guest behavior, authorization denial, cross-tenant denial, and restricted-string leak checks.

### Layer 4 — Livewire/component tests

Stateful forms, editors, comments, notifications, matter activity, and permission-sensitive rendering.

### Layer 5 — Browser journeys

Keep a small stable set: create matter; ingest and retrieve a document; calculate and revise a deadline; create/revise/approve/export a draft; share and revoke external access; apply and release a legal hold.

### Layer 6 — Architecture tests

Executable enforcement of the doors listed above.

### Layer 7 — Schema tests

Indexes, tenant keys, foreign keys, unique constraints, append-only tables, encrypted fields, and migration compatibility.

### Layer 8 — AI evaluations

Use fixed synthetic matters and assert tool choice, allowed citations, provenance, schema validity, abstention, and approval behavior. Do not assert exact prose. AI output never substitutes for deterministic authorization, deadlines, retention, or audit logic.

---

## 6. Agent swarm protocol

Parallel execution is allowed only when file ownership and contracts are disjoint.

### Required controls

1. **One orchestrator:** owns planning, shared interfaces, integration, and final verdicts.
2. **One branch and worktree per feature:** never place two builders in the same working tree.
3. **Approved contracts first:** parallel work starts only after shared interfaces are frozen.
4. **Ownership map:** every wave lists agent-owned directories and orchestrator-owned files.
5. **Shared files are protected:** route registration, service-provider bindings, shared layouts, core enums, and migration ordering belong to the orchestrator unless explicitly delegated.
6. **Integration tickets are explicit:** a “droppable” component is not silently wired into another agent’s page.
7. **Independent review:** the builder does not certify its own merge.
8. **CI is the referee:** local success does not override merged-branch gates.
9. **No opportunistic cleanup:** baseline debt is either scoped into the brief or left alone.
10. **Stop on contract drift:** if implementation reveals a contract error, update and reapprove the relevant artifact before continuing.

### Example ownership map

| Worker | Owns | Must not edit |
|---|---|---|
| Identity | auth, orgs, invitations | drafting, documents |
| Documents | document domain, storage adapter | matter UI, routes |
| Matters | matter domain and screens | storage internals |
| Infrastructure | CI, deploy scripts | domain behavior |
| Orchestrator | shared routes, bindings, integration | feature internals |

The actual first wave should remain smaller than this example until the base repository, namespaces, and architecture tests are stable.

---

## 7. Drafting-specific stop gate

Drafting is the one area that should not be distributed until its mechanics are decided. Before creating drafting tickets, approve `docs/DRAFTING_ARCHITECTURE.md` with answers to the following.

### Source of truth

Choose one canonical editable representation:

- structured sections and blocks in the database;
- a document format in an organization repository;
- or a hybrid with an explicit authority rule.

Git history cannot be treated simultaneously as the authoritative content store and merely an approval log without defining reconciliation.

### Required boundaries

- **Draft service:** create, revise, compare, approve, reject, and restore versions.
- **Template service:** template schema, variables, clauses, validation, and version pinning.
- **Renderer:** canonical draft to HTML preview, PDF, and DOCX; rendering must not alter content.
- **Citation/provenance service:** every fact or quotation links to a source document and location.
- **AI drafting gateway:** exposes narrowly defined tools rather than unrestricted filesystem or database access.
- **Approval service:** records who approved which exact version and prevents post-approval mutation.
- **Repository adapter:** if Git is retained, defines branch, commit, merge, tag, and conflict semantics.

### Questions that must be decided

1. What is the canonical representation of captions, numbered paragraphs, tables, signature blocks, exhibits, and inline formatting?
2. Does each organization receive a repository, or does Git track released drafts only?
3. How are concurrent human edits merged?
4. What constitutes an immutable approved version?
5. Which edits may AI make without approval, and which require an approval event?
6. How are citations preserved through HTML, PDF, and DOCX export?
7. Can a template update affect an existing draft, or is the template version pinned at creation?
8. Does Laravel own the agent loop, or does it call a separate Python/ACP service?
9. Which OCR/search pipeline pieces are ported from Vision and which remain a service?
10. What is the failure mode when the model, renderer, repository, or search service is unavailable?

No framework or AI SDK decision resolves these questions by itself.

---

## 8. Recommended first build sequence

### Phase 0 — Repository and standards

Create the Laravel repository, establish the system documents, install the test/style/static-analysis toolchain, connect PostgreSQL, configure CI, and establish development deployment. Record versions from the actual lockfiles; do not code from remembered framework APIs.

**Exit verdict:** a minimal authenticated page passes CI and deploys automatically using synthetic data.

### Phase 1 — Identity, tenancy, and audit foundation

Organizations, users, invitations, roles, tenant resolution, matter-independent authorization primitives, and append-only audit events.

**Exit verdict:** cross-tenant access fails without leakage and both allowed and denied sensitive actions can be audited.

### Phase 2 — Walking skeleton

Build the smallest vertical slice that proves the permanent seams:

> create matter → attach document → authorize read → audit read/denial → search within the permitted matter

Use local or simple storage behind `DocumentStore`; swap infrastructure later without changing callers.

**Exit verdict:** an authorized matter member can upload and retrieve a document; another tenant and an unauthorized same-tenant user cannot discover or retrieve it; every attempt has the expected audit behavior.

### Phase 3 — Drafting proof

After the drafting architecture is approved:

> choose versioned template → create structured draft → AI proposes a section with provenance → human revises → approve exact version → render preview and export

Do not begin with a full word processor. Prove content authority, versioning, rendering fidelity, provenance, and approval first.

### Phase 4 — Matter lifecycle and calendaring

Deepen matter intake, parties, assignments, activities, deadline rules, recalculation, reminders, and external calendar adapters.

### Phase 5 — Retention, holds, and external sharing

Implement only after identity, documents, matters, and audit are proven because these features cross destructive and external authorization boundaries.

---

## 9. Minimal templates

### Feature brief

```markdown
# <ID> — <Feature> — Strategic brief

**Status:** DRAFT

## Goal
<One sentence>

## Claims
| ID | Capability | Verdict | RFI anchor |
|---|---|---|---|
| C-01 | <capability> | <named binary test> | <requirement ID> |

## Governing decisions
- [D] <approved decision>
- [O] <unresolved question> → <stop condition>

## Security and audit
- Actors:
- Data classes:
- Allow events:
- Denial events:
- Leak sentinels:

## Evidence and fixtures
<canonical test scenario and source evidence>

## Pre-mortem
- Failure:
- Assumption:
- Unknown:
- Mitigation:

## Non-goals
<explicit exclusions>

## Approval
- [ ] Product owner approved
```

### Archaeology result

```markdown
| Thing | Location | Verdict | Constraint |
|---|---|---|---|
| <code or artifact> | <path> | REUSE / EXTEND / NEW / CONFLICT | <constraint> |

## Files to touch
| File | Action | Owner | Reason |
|---|---|---|---|
| <path> | create/edit | <worker> | <reason> |

## Protected files
- <path and reason>

## Baseline
- Tests:
- Architecture tests:
- Pint:
- PHPStan:
- Asset build:
```

### Ticket

```markdown
## Ticket N — <Outcome>

**Files:** <owned files>
**Inputs:** contract sections, fixture cases
**Dependencies:** earlier tickets only
**Forbidden edits:** <protected files>

### Work
- [ ] <implementation step>

### Verdict
- <named test>
- Architecture suite green
- Pint green
- PHPStan checkpoint, when required
```

---

## 10. Development journal

Use the Haven journal structure:

```text
dev-journal/
  bug-fixes/
  domain-knowledge/
  progress/
```

- **Bug fixes:** symptom → root cause → fix → guard. No guard, no completed fix.
- **Domain knowledge:** topic-named, durable findings not already in formal docs.
- **Progress:** done → in progress → next → decisions and open threads.

Formal decisions stay in the system and feature documents; the journal links to them rather than replacing them.

---

## 11. Approval checklist before coding

The following should be approved before the first feature swarm begins:

- [ ] Greenfield Laravel repository is confirmed.
- [ ] Product blueprint and RFI requirement matrix have canonical homes.
- [ ] Tenant model and matter authorization boundary are decided.
- [ ] User onboarding is invitation-based or otherwise explicitly decided.
- [ ] Audit event model and append-only rule are decided.
- [ ] `DocumentStore` boundary and initial storage adapter are decided.
- [ ] CI, branch protection, independent review, and dev deployment are operational.
- [ ] Synthetic seed matter and adversarial authorization fixtures exist.
- [ ] Drafting work is held until `DRAFTING_ARCHITECTURE.md` is approved.
- [ ] Initial feature briefs have verdicts written by the orchestrator and approved by the product owner.

## 12. Immediate next artifacts

To start building without recreating Haven wholesale, produce these in order:

1. `docs/PRODUCT_BLUEPRINT.md`
2. `docs/RFI_REQUIREMENTS_MATRIX.md`
3. `docs/SECURITY_AND_TENANCY.md`
4. `docs/DOMAIN_MODEL.md`
5. `docs/TESTING_STRATEGY.md`
6. `docs/DRAFTING_ARCHITECTURE.md`
7. `specs/001-identity-tenancy/00-brief.md`
8. `specs/INFRA-001-ci-deploy/00-brief.md`
9. `specs/002-matter-document-skeleton/00-brief.md`

The first two briefs may be planned together. Execution should begin with repository/CI bootstrap and identity/tenancy; the matter-document skeleton follows once those interfaces are fixed.

---

## Source record

This protocol was derived from the following files in the Haven repository on the shared development server:

- `/opt/haven/AGENT_GUIDE.md`
- `/opt/haven/app/AGENTS.md`
- `/opt/haven/app/docs/Planning_Protocol.md`
- `/opt/haven/app/docs/Dev_Workflow.md`
- `/opt/haven/app/docs/Testing_Strategy.md`
- `/opt/haven/app/docs/UI_Standards.md`
- `/opt/haven/app/docs/Walking_Skeleton_Spec.md`
- `/opt/haven/app/docs/Roadmap.md`
- `/opt/haven/app/dev-journal/README.md`
- `/opt/haven/app/specs/006-thread-notifications/`
- `/opt/haven/app/.github/workflows/tests.yml`

The repository inspection confirmed a clean `main` branch at commit `edd83fc`. Haven’s protocol was extracted and translated; Haven’s domain rules were not imported into this legal application protocol.
