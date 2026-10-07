# 004 — Invitation onboarding UI — Decision log

> One home per fact. Formal decisions live here; the dev journal links here rather than duplicating.

---

## Entries

| Date | ID | Decision | Context | Alternatives considered | Consequences | Status |
|---|---|---|---|---|---|---|
| 2026-10-07 | 004-D01 | Extend the existing invitation controllers with Blade responses via `wantsJson()` negotiation instead of building new controllers | The JSON endpoints are complete and tested (001); duplicating them would create two homes for invitation logic | New web-only controllers; full duplication of the JSON API | One home per fact: business rules stay in the service, presentation branches in the controllers | [D] |
| 2026-10-07 | 004-D02 | Accept page offers two paths: new users register bound to the token (existing `register.store` + `invitation_token`), existing users sign in then accept (existing `accept` endpoint) | Matches the backend's designed split (`CreateNewUser` T-04 path vs `InvitationService::accept`) | A single bespoke accept-registration endpoint | No new auth logic; both paths already tested at the service level | [D] |
| 2026-10-07 | 004-D03 | After creating an invitation, the admin UI displays the accept link once for copy-paste; email delivery configuration is deferred | Mail transport is not configured on the server; the queued `InvitationMail` would sit unsent | Block the spec on mail setup; silently assume mail works | Pragmatic pilot path now; a mail-setup task is recorded as deferred, not forgotten | [D] |
| 2026-10-07 | 004-D04 | Minimal scope: the invitations page only — no admin dashboard, no user directory, no role management UI | Ian's sequencing call: open the human door before building business logic; scope discipline keeps it to a day of work | Full admin console as part of this spec | Invitation UI ships fast; broader admin surfaces get their own specs later | [D] |
| 2026-10-07 | 004-D05 | No "resend invitation" endpoint — revoke + recreate covers it | Keeps the contract surface minimal; each invitation keeps its single-use token semantics | Resend reusing the same token (weakens single-use clarity) | One less endpoint to secure and test | [D] |
