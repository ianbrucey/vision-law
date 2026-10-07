# AGENTS.md — VISION LAW

Agent handoff for this repository. Read this first, then the docs referenced below.
This file and `CLAUDE.md` are exact mirrors (Haven convention: update both or neither).

## What Vision Law is

A legal and contract management application built to answer Georgia DOT
eRFI-002022-2027 ("Legal and Contract Software"). Greenfield Laravel rebuild —
this repo starts from a clean Laravel 13 scaffold, not from the prototype.

> The Python application at `/opt/vision` is the **reference implementation only**.
> Its document-intelligence pipeline (upload → OCR → chunk → embed → search) is
> the hard-won piece worth porting or wrapping as a service. Do not modify
> `/opt/vision`, and do not copy its structure as architecture.

Core domains (from the RFI): legal document management · legal calendaring with
a deadline rules engine · case/matter management · platform security and
integrations (Microsoft 365, Adobe, Bluebeam) · AI-assisted drafting with
governance controls.

## Read these first (all in `docs/`)

| Doc | Why |
| --- | --- |
| `Build_Protocol.md` | **The contract.** Extracted from the Haven repo's proven agent protocol: planning layers, feature lifecycle, gates, swarm controls. [D] is law; [O] stops work. |
| `Planning_Protocol.md` | **How features move** from brief → archaeology → council artifacts → tickets → verdicts. Full protocol for anything adding a route, migration, or screen; lightweight mode for small fixes. |
| `DRAFTING_ARCHITECTURE.md` | **Does not exist yet — stop gate.** No drafting tickets until this is approved (see Build_Protocol §7). |

## Non-negotiables (get these wrong and nothing else matters)

- **Matter-level access control is the core feature, not a feature.**
  Access = f(role, organization, matter relationship), enforced **only server-side**
  through one policy layer. Nothing client-side ever gates data.
- **Every read of restricted content writes an audit event — denials are audited too.**
  The audit log is append-only, written only through `AuditLogger`.
- **Drafting follows the git working-copy pattern.** The agent drafts on a branch;
  a human merge is the approval event. UI code never mutates draft persistence
  directly — all changes pass through the drafting service.
- **Retention and legal holds are destructive boundaries.** Disposition runs only
  after identity, documents, matters, and audit are proven.
- **No privileged document text** in notifications, logs, queued payloads, exports,
  or error messages unless the feature's contract explicitly permits it.
- **No deadline math outside the rules engine.** No model calls outside the AI gateway;
  privileged AI tools require recorded human approval.

## Stack (decided)

Laravel 13 · PHP 8.4 · PostgreSQL + pgvector · Pest · Laravel AI SDK (`laravel/ai`,
provider-agnostic) for drafting assistance · Blade + Tailwind.
Database queues. New Laravel apps ship stub `AGENTS.md`/`CLAUDE.md` — ours replace them.

## Working rules

- **Features follow `docs/Planning_Protocol.md`.** No code before the brief is approved;
  fixtures before logic; atomic backend-out tickets; green verdicts.
- **Decisions are tagged [D] / [P] / [O].** [D] is buildable. [P] is reviewable, not
  implementation authority. [O] is a question — work depending on it stops or
  explicitly defers it. Never guess on [O].
- **Search before building framework features.** For anything beyond plain CRUD —
  policies & gates, queues, storage/signed URLs, auth, notifications, AI SDK — read
  the version-matched Laravel 13 docs first and cite the URL. Never code from memory.
- **Tests ship with every feature.** `php artisan test` green before a task is done;
  **never weaken or skip an assertion to get green** — fix the cause, or update the
  decision doc first, then the test.
- **Quality gates:** `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`
  (level 7) green before merge. CI runs on PostgreSQL, not SQLite.
- **Architecture laws are executable.** Tenant/matter authorization, audit, storage,
  drafting, deadline, AI-gateway, export, route-auth, and tenant-key doors are
  enforced by `tests/Architecture` tests, not prose.
- **Keep the dev journal** (`dev-journal/`): `bug-fixes/` (symptom → root cause →
  fix → the guard that prevents recurrence), `domain-knowledge/` (topic-named),
  `progress/` (done / in progress / next / decisions). Scan it before starting work.
- Work happens in `feat/<NNN>-<slug>` branches. The builder never approves its own merge.
- Local dev: `composer run dev`. Tests: `php artisan test`.
