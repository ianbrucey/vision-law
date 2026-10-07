# AGENTS.md — Vision Law

Agent handoff for this repository. Read this first, then the docs in the table below.
This file and `CLAUDE.md` are exact mirrors — update both or neither.

## What Vision Law is

Greenfield Laravel 13 legal/contract management application being built to
respond to Georgia DOT eRFI-002022-2027 ("Legal and Contract Software").
The RFI closes **October 28, 2026**.

> The Python application at `/opt/vision` is the **reference implementation only**
> — read-only inspiration, not a dependency. Its document-intelligence pipeline
> (upload → OCR → chunk → embed → search) is the hard-won piece worth porting or
> wrapping as a service. Do not modify `/opt/vision`, and do not copy its
> structure as architecture.

Core domains (from the RFI): matter management · legal document management ·
legal calendaring with a deadline rules engine · platform security and
integrations (Microsoft 365, Adobe, Bluebeam) · AI-assisted drafting with
governance controls.

## Read these first (all in `docs/`)

| Doc | Why |
| --- | --- |
| `PRODUCT_BLUEPRINT.md` | **The contract.** The product definition; system-level standing law. |
| `RFI_REQUIREMENTS_MATRIX.md` | Traceability: every RFI requirement maps to a claim, feature, and verdict. |
| `ROADMAP.md` | Build order and milestones; what comes before the Oct 28 RFI response. |
| `DRAFTING_ARCHITECTURE.md` | **Stop gate — does not exist yet.** No drafting tickets until it is approved. |
| `UI_Standards.md` | UI/UX standards for every screen and component. |
| `Planning_Protocol.md` | How features move: brief → archaeology → council artifacts → plan → execution → freeze. |
| `Build_Protocol.md` | Agent build discipline: planning layers, feature lifecycle, gates, swarm controls. [D] is law; [O] stops work. |

## Non-negotiables (get these wrong and nothing else matters)

- **Matter is the central object.** Every document, deadline, retention rule, and
  share attaches to a matter. There is no orphaned legal content.
- **Authorization is matter-level and role-based, enforced ONLY server-side.**
  Nothing client-side ever gates data. One policy layer; access =
  f(role, organization, matter relationship).
- **Every sensitive read/write writes an append-only AuditEvent — denials audited too.**
  The audit log is append-only and written only through `AuditLogger`.
- **Drafting follows the git working-copy pattern.** The agent drafts on a branch;
  a human merge is the approval event. Filed/sent versions are tagged.
- **Retention policies and legal holds are enforced by the system.** Disposition
  requires authorization **and** an audit event; an active hold blocks disposition.

## Decision tags

Every standing doc tags claims **[D]** decided / **[P]** proposed / **[O]** open.
- **[D]** is law — buildable.
- **[P]** is reviewable, not implementation authority.
- **[O]** is an open question. **Never guess on [O]** — record it as an open
  question; work depending on it stops or explicitly defers it.
- Record every decision with its date.

## Stack (decided)

Laravel 13 · PHP 8.4 · PostgreSQL + pgvector · Pest · Pint · PHPStan level 7 ·
Laravel AI SDK (first-party) for drafting assistance · Blade + Tailwind.
Database queues. CI: `.github/workflows/tests.yml`
(Pint → PHPStan → Pest on Postgres, not SQLite).

## Working rules

- **Features follow `docs/Planning_Protocol.md`.** No code before the brief is approved.
- **Tests ship with every feature.** `php artisan test` green before a task is done;
  **never weaken or skip an assertion to get green** — fix the cause, or update the
  decision doc first, then the test.
- **Search official version-matched docs before building complex Laravel features.**
  For anything beyond plain CRUD (policies & gates, queues, storage/signed URLs,
  auth, notifications, AI SDK), read the Laravel 13 docs first and cite the URL.
  Never code framework APIs from memory.
- **Keep the dev journal** (`dev-journal/`): `bug-fixes/` (symptom → root cause →
  fix → the recurrence guard), `domain-knowledge/` (topic-named),
  `progress/` (done / in progress / next / decisions). Scan it before starting work.
- Work happens in `feat/<NNN>-<slug>` branches. The builder never approves its own merge.
- Local dev: `composer run dev`. Tests: `php artisan test`.
