# 003 — Public site + auth pages — Contract

**Status:** DRAFT (→ APPROVED)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-07
**Schema:** `02-schema-delta.md`

> The contract is settled BEFORE tickets. Routes, requests, validation, errors,
> authorization, and redirects are decided here — not during implementation.
> Views may request only what this contract exposes.

---

## Domain service methods

None — this spec adds no domain services. All auth behavior is the 001 backend, reused as-is.

## Routes

| Method | Path | Action | Auth | Authorization rule |
|---|---|---|---|---|
| GET | `/` | closure → `view('public.landing')` | guest (public) | — (marketing surface; already on `AuthenticatedByDefaultTest` guest list via the existing root entry) |
| GET | `/login` | `Auth\LoginController@create` | guest | — (already on the test guest list; authenticated users redirect to `/` per guest middleware) |
| GET | `/register` | `Auth\RegisteredUserController@create` | guest | — (already on the test guest list; registered only when `Features::registration()` is enabled — same flag gate as the existing POST route, per 001-D09) |
| POST | `/login` | Fortify (existing) | guest | UNTOUCHED — 001 pipeline, lockout, and error catalog |
| POST | `/register` | `RegisteredUserController@store` (existing) | guest | UNTOUCHED — 001 `CreateNewUser` contract |

**Fortify view-route conflict check (done in archaeology):** with `views => false`, Fortify registers no GET view routes. Route names `login` and `register` are FREE; `register.store`, `login.store`, `password.email` are TAKEN and must not be reused. T-05 verifies with `php artisan route:list`.

## Requests & validation

### GET views

No inputs. Controllers return the view with **no user data** (C-04). The register view receives an optional `invitation_token` from the query string when arriving from an invitation link — rendered as a hidden field, never displayed.

### POST /register (existing — restated for the view's form fields)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `name` | string | yes | max:255 | 422 → redirect back with errors (inline under field) |
| `email` | email | yes | max:255, unique per org | 422 → **generic** "If this email is available, a verification link was sent" — no enumeration (001) |
| `password` | string | yes | min:12, breached-password check | 422 → redirect back with errors |
| `invitation_token` | string | conditional | valid, unexpired, email-bound | 422 `invitation_invalid` (generic) |

**Explicitly out:** the mockup's "Organization name" field is NOT submitted until 003-O1 is answered. The default build posts exactly the fields above.

### POST /login (existing — restated for the view's form fields)

| Field | Type | Required | Validation | Error on invalid |
|---|---|---|---|---|
| `email` | email | yes | — | 422 `{code:"invalid_credentials"}` (identical for unknown email) |
| `password` | string | yes | — | 422 `{code:"invalid_credentials"}` |

## Error catalog

| Situation | HTTP | Body / behavior | Audit event |
|---|---|---|---|
| Registration validation failure | 302 back | errors under the field via `x-ui.field`; `old()` preserved | none (rejected pre-mutation, per 001) |
| Duplicate email on registration | 302 back | **generic** message — no enumeration | none (per 001) |
| Login with wrong password / unknown email | 302 back | `{code:"invalid_credentials"}` message (identical both cases) | `auth.login.failed` (001) |
| 5th failed login | 429 | `{code:"locked_out", retry_after: 900}` | `auth.login.locked_out` (001) |
| Registration attempted with `FORTIFY_REGISTRATION=false` | 404 | route not registered | none |

## Audit events

**None new.** This spec reuses `auth.login`, `auth.login.failed`, `auth.login.locked_out`, `user.created` from 001. The view layer writes no audit events.

## Redirects (003-D02 [P])

| Flow | Target | Mechanism |
|---|---|---|
| Login success | `intended()` else `/` | `config('fortify.redirects.login') = '/'` — temporary until the app home spec lands |
| Logout | `/` | `config('fortify.redirects.logout') = '/'` |
| Registration success (no auto-login, unverified) | `/login` + flash "Check your email to verify your account, then sign in." | `config('fortify.redirects.register') = '/login'`; flash set by the view response path |
| Authenticated user hits `/login` or `/register` | `/` | `guest` middleware default (`RedirectIfAuthenticated` → `RouteServiceProvider::HOME`; confirmed `/` at T-05, adjusted if the constant points elsewhere) |

## Role-visible data

| Role | Sees | Must never see |
|---|---|---|
| anonymous (guest) | Marketing copy; synthetic audit sample; sign-in / register forms | Any user/org/session data (C-04) |
| authenticated user | Redirected off guest pages | N/A |

Views receive no models, no collections, no session values beyond flash + validation errors.

## External integration failure behavior

None — no external calls from this spec's surfaces.

## AI involvement

No AI involvement.
