# 001 — Foundation: authentication, RBAC, and audit logging — Decision log

> One home per fact. Formal decisions live here; the dev journal links here
> rather than duplicating.

---

## Format

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-06 | 001-D01 | Use **Laravel Fortify** (headless) for the auth backend with custom Blade views, not Breeze or Jetstream | Need login/2FA/reset/verify without inheriting throwaway scaffolding | Breeze (Blade scaffolding we'd discard against UI standards); Jetstream (too opinionated: teams billing, API tokens); hand-rolled (larger attack surface) | Auth UI is ours to build per UI standards; must wire Fortify actions ourselves | [D] |
| 2026-10-06 | 001-D02 | Use **spatie/laravel-permission** for org-level RBAC; matter-scoped access via a custom `matter_grants` table (not spatie teams) | PLT-11 wants five fixed roles + matrix; PLT-12 wants per-matter user/team grants with expiry and role override | spatie teams feature (doesn't model expiry/override cleanly); pure custom RBAC (reinvents the wheel) | Must publish spatie migrations and convert morph columns to UUID; permission matrix seeded from `03-contract.md` | [D] |
| 2026-10-06 | 001-D03 | Create a **minimal `matters` stub table** in this feature (id, org_id, matter_number, title, status) | Matter-scoped authorization verdicts need a real securable object; full matter lifecycle is a later feature | Foreign-key to a not-yet-existing table (impossible); testing grants against a fake route (no real proving ground) | Matter-management feature EXTENDs this table via new migration — never edits it | [D] |
| 2026-10-06 | 001-D04 | Enforce audit append-only at the **database level** (`REVOKE UPDATE, DELETE`) plus model-event guards and a hash chain | PLT-13 explicitly requires DB-level enforcement; application-only guards are bypassable | Trigger-based prevention (harder to audit); app-only (insufficient per RFI) | Seeds and tests must never UPDATE audit rows; hash-chain concurrency handled via per-org advisory lock | [D] |
| 2026-10-06 | 001-D05 | **Defer SSO** (SAML 2.0 PLT-06, OAuth2 PLT-07) to a separate feature/spec | Foundation scope is already 14 claims; SSO has distinct protocol risk (XML signatures, IdP metadata) | Bundling SSO now (schedule risk, diluted verdicts) | Separate spec later; `users` table must keep `provider`/`provider_id` out until then (no speculative columns) | [D] |
| 2026-10-06 | 001-D06 | **Defer `05-ui-mockup.html`** to the brief-approval session with Ian | UI_Standards is [P] (undecided); a mockup built against undecided standards risks rework; Ian's approval session is the natural venue | Building the mockup now against provisional standards (rework risk) | No Blade work in T-04/T-05/T-07 until mockup is APPROVED; mockup scope is fixed in `05-ui.md` so the deferral is bounded | [D] |
| 2026-10-06 | 001-D07 | Breached-password check **fails closed** (reject with retryable error) if the k-anonymity API is unreachable | Availability vs. security tradeoff on the registration path | Fail open (weakens PLT-01 silently) | Monitor; revisit if availability suffers | [D] |
| 2026-10-06 | 001-D08 | Argon2id test-env parameters are **weaker and documented** in `phpunit.xml` | Full 64 MB × 3 iterations per test login would make the suite unusably slow | Full-strength in tests (slow suite, skipped tests); plaintext test passwords (never) | Test env is explicitly NOT the security posture; production config asserted by a dedicated test | [D] |

## Open items

None. All load-bearing questions for this feature are decided. (SSO is a separate feature, not an open item of this one.)
| 2026-10-06 | 001-D09 | Self-registration is **ALLOWED**; a config flag is retained (default open) so it can be disabled per-environment | Ian’s brief approval; PLT-01 describes self-registration | Invite-only default (the brief’s original recommendation) | Registration flow must honor the flag; the flag-off path gets its own verdict test | [D] |
| 2026-10-06 | 001-D10 | Pin **laravel/fortify v1.41.0** and **spatie/laravel-permission 8.3.0** for the foundation feature (resolved by composer against Laravel 13.35.0) | T-01 package installation; fortify requires illuminate/* ^11\|^12\|^13, spatie requires illuminate/* ^12\|^13 — both declare Laravel 13 support | Hand-rolled auth backend (rejected — larger attack surface, per 001-D01 pre-mortem) | Fortify headless backend + spatie RBAC proceed per 001-D01/001-D02; spatie config+migrations published (UUID morph conversion in T-02) | [D] |
| 2026-10-06 | 001-D11 | Audit append-only enforced by a BEFORE UPDATE/DELETE trigger raising an exception (fires for the table owner, unlike REVOKE); REVOKE retained as defense-in-depth | T-02 probe proved REVOKE is owner-ineffective (Postgres table owners bypass REVOKE); PLT-13 requires genuine DB-level enforcement | Two-role pattern — migrator-owner vs runtime app role (more moving parts; every future migration would need default-privilege grants) | Trigger must be recreated if audit_events is ever rebuilt; migrate:fresh unaffected (DDL not blocked) | [D] |
