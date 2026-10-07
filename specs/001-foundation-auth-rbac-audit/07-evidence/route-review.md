# 001 — Foundation — T-07 route-table review walk

**Date:** 2026-10-06 · **Reviewer:** Ticket 7 worker
**Scope:** every route in `03-contract.md` §Routes, checked for
**authorization-before-data-access** (contract rule: "Every route evaluates
its authorization rule server-side BEFORE any data access"). Each route also
verified against the live route table (`php artisan route:list`) for its
middleware stack.

**Result: 30/30 pass, 0 flags.** No route accesses data before its
authorization check; no fix was required. Three observations (not flags) are
recorded at the end.

Conventions: "authz" = the authorization rule; "order" = where the check
runs relative to data access (middleware = before controller, i.e. before
any data access).

## Guest routes (contract guest list)

| # | Route | Authz | Order | Verdict |
|---|---|---|---|---|
| 1 | `POST /register` | `guest` middleware + invitation-token-or-open-registration rule (001-D09) in the `CreatesNewUsers` action | middleware first; token/flag validated inside the action before `User::create` | **pass** |
| 2 | `GET /register` | guest | **not registered** — headless Fortify mode registers no GET view routes (verified T-01); contract row inoperative by design | **pass** (absent) |
| 3 | `POST /login` | `guest` middleware; brute-force lockout (C-03) | middleware first; lockout counters checked before credential verification | **pass** |
| 4 | `GET /login` | guest | not registered (headless; see #2) | **pass** (absent) |
| 5 | `POST /forgot-password` | `guest` + throttle; app override sends a link only for existing users, identical generic response either way | user lookup is the account-existence check inherent to the flow; the *response* is enumeration-free, which is the contract's requirement | **pass** |
| 6 | `GET /forgot-password` | guest | not registered (headless; see #2) | **pass** (absent) |
| 7 | `GET /reset-password/{token}` | `guest` | closure returns a static JSON message; **zero data access** | **pass** |
| 8 | `POST /reset-password` | `guest` (Fortify `NewPasswordController`) | token validated before password update | **pass** |
| 9 | `POST /two-factor-challenge` | `guest` (challenged); app `TwoFactorChallengeController` | `hasChallengedUser()` (session-bound) checked first, then lockout, then Fortify — challenge authorization precedes any data | **pass** |
| 10 | `GET /two-factor-challenge` | guest (challenged) | not registered (headless; see #2) | **pass** (absent) |
| 11 | `GET /invitations/{token}` | `guest`; token validity IS the authorization | `InvitationService::findValid()` runs before any render; invalid/expired/revoked → 404 with no reason given (no enumeration) | **pass** |

## Authenticated routes

| # | Route | Authz | Order | Verdict |
|---|---|---|---|---|
| 12 | `POST /logout` | `auth` (Fortify) | session termination only; no data read | **pass** |
| 13 | `GET /email/verify/{id}/{hash}` | `auth` + `signed` + throttle (Fortify) | signature + auth enforced by middleware before verification | **pass** |
| 14 | `POST /email/verification-notification` | `auth` + throttle (Fortify) | — | **pass** |
| 15 | `GET /user/two-factor-authentication` etc. (qr-code, secret-key, recovery-codes, confirm) | `auth` + `password.confirm` (Fortify) | re-authentication precedes secret material | **pass** |
| 16 | `POST /invitations/{token}/accept` | `auth`; `InvitationController@accept` aborts 401 without a user; `InvitationService::accept()` rejects email mismatch generically before any mutation | auth middleware → email-bound check inside the service transaction before grant/user changes | **pass** |
| 17 | `GET /sessions` | `auth`; `SessionController@index` | query scoped `where('user_id', $user->getKey())` — self only | **pass** |
| 18 | `DELETE /sessions`, `DELETE /sessions/{id}` | `auth` + `password.confirm` | password re-auth runs before the controller; deletes scoped to the caller's `user_id` | **pass** |

## Admin routes (`auth` + `RequireOrgAdmin`; 403 → `user.admin.denied`, audit routes → `audit.viewer.denied`)

| # | Route | Authz | Order | Verdict |
|---|---|---|---|---|
| 19 | `GET/POST /admin/users`, `GET/PUT/PATCH/DELETE /admin/users/{user}` | `RequireOrgAdmin` (org_admin only) | every query scoped `where('org_id', $admin->org_id)`; cross-org → 404 `not_found`; admin cannot change/deactivate self (422 pre-mutation) | **pass** |
| 20 | `GET/POST /admin/teams`, `GET/PUT/PATCH/DELETE /admin/teams/{team}` | `RequireOrgAdmin` | same org scoping; membership computed fresh per request | **pass** |
| 21 | `GET/POST /admin/invitations`, `DELETE /admin/invitations/{invitation}` | `RequireOrgAdmin` | org-scoped listing/revoke; `matter_id` on invite validated `Rule::exists(...)->where('org_id', …)` — cross-org matter → 404 | **pass** |
| 22 | `GET /admin/audit-events` *(new, T-07)* | `auth` + `RequireOrgAdmin:audit.viewer.denied` | denial audited as `audit.viewer.denied` (contract error catalog); query hard-scoped to `org_id = $admin->org_id` — 001-D13 system-org events excluded by construction | **pass** |
| 23 | `GET /admin/audit-events/export` *(new, T-07)* | `auth` + `RequireOrgAdmin:audit.viewer.denied` + `password.confirm` | middleware order: auth → org_admin check → password re-auth → controller; same org scoping; export audited as `audit.exported` | **pass** |

## Matter routes (`auth` + `RequireMatterAccess:<action>`)

| # | Route | Authz | Order | Verdict |
|---|---|---|---|---|
| 24 | `GET /matters/{matter}` | `matter.access:view` | middleware resolves the matter and authorizes **before** the controller runs; the controller reads the authorized model from request attributes and never re-resolves | **pass** |
| 25 | `POST /matters/{matter}/grants` | `matter.access:grant` (matter_admin+) | subject constrained `Rule::exists(users\|teams, id)->where('org_id', $matter->org_id)` — a cross-org subject is rejected 422 before any write; grant create + audit commit atomically | **pass** |
| 26 | `DELETE /matters/{matter}/grants/{grant}` | `matter.access:grant` | grant row resolved with `where('matter_id', $matter->getKey())` — a grant from another matter → 404 (no cross-matter IDOR); last-owner guard rejects 422 pre-mutation | **pass** |

## Authorization-before-data-access: mechanism inventory

- **Middleware-first:** `RequireOrgAdmin` / `RequireMatterAccess` run before
  controllers; controllers receive already-authorized models or 403/404.
- **Query scoping:** admin controllers scope every query by the admin's
  `org_id`; session controllers by `user_id`; grant destroy by `matter_id`.
- **Pre-mutation guards:** last-owner removal, self role-change/deactivation,
  cross-org subject — all rejected before any write; validation failures
  write no audit rows (contract error catalog).
- **Leak rules honored:** cross-org / no-grant → 404 `{code:"not_found"}`;
  insufficient role on a visible object → 403 `{code:"forbidden"}`.

## Observations (not flags — no fix required)

1. **`GET /`** — framework default landing page (`view('welcome')`), no
   `auth` middleware, not in the contract's route table. It renders a static
   view with **zero data access** and is asserted 200 by `ExampleTest`. The
   C-14 authenticated-by-default architecture test carries it as a named,
   reasoned exception rather than silently passing it.
2. **`GET /up`** — Laravel health check (`bootstrap/app.php`
   `health: '/up'`). Public by design; carries no app data.
3. **`GET|PUT storage/{path}`** — registered by the framework's
   `FilesystemServiceProvider::serveFiles()` for the `local` disk
   (`storage/app/private`), with **no middleware at all**. Nothing in this
   feature writes to storage (C-14 door 1 forbids `Storage` in controllers),
   so the served tree is effectively empty — but a future file-handling
   phase (PLT-23+) must either ensure privileged files never land on a
   served disk or set `serve: false` on the local disk. Recorded here so the
   decision is made deliberately, not discovered.
4. **Contract GET view rows** (`GET /login`, `GET /register`,
   `GET /forgot-password`, `GET /two-factor-challenge`) are absent in
   headless Fortify mode — only the POST/API surface exists. If server-side
   rendered auth views ever return, each must re-enter this walk.
