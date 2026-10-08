# 008 — Passkey authentication (WebAuthn) — Strategic brief

**Status:** DRAFT — awaiting Ian Bruce approval (drafted 2026-10-08 at Ian's request to pull passkeys forward from the ROADMAP "Deferred from v1" list)
**Author:** Lotus (orchestrator)
**Date:** 2026-10-08

---

## Goal

An org admin can satisfy Vision Law's MFA requirement with a **passkey** (WebAuthn/FIDO2) instead of an authenticator app — no download, no six-digit codes: enrollment is one biometric/PIN gesture on a device the user already owns, and every later login challenge is the same gesture. TOTP remains fully supported as the baseline factor; passkeys become the recommended primary. This is the least-friction factor that enterprise and government security reviews recognize — phishing-resistant, and aligned with NIST SP 800-63 / FIDO guidance — so the friction problem and the enterprise posture are solved by the same move.

## Claims

| ID | Capability | Verdict (named binary test) | RFI anchor |
|---|---|---|---|
| C-01 | A user in setup mode (org admin without MFA) is offered **both** enrollment paths on the enrollment page — passkey and TOTP — and the setup-mode allowlist reaches only those surfaces | `test_setup_mode_offers_passkey_and_totp_enrollment` — setup-mode session GET enrollment page shows both paths; passkey ceremony routes are reachable; every non-allowlisted route still 302s to enrollment | PLT-05 |
| C-02 | A user can register a passkey through the UI ceremony (options → authenticator response → stored credential) and sees it listed with a user-chosen label | `test_user_registers_passkey_via_ui` — full ceremony against a virtual authenticator stores one credential row bound to the user; the enrollment page lists it by label | PLT-05 |
| C-03 | Passkey enrollment satisfies the MFA requirement for org admins: confirming a passkey clears the setup-mode flag and the session becomes unrestricted | `test_passkey_enrollment_clears_setup_mode` — org admin with no TOTP registers a passkey → setup flag cleared, previously-denied route returns 200 | PLT-05 |
| C-04 | At login, a user with a passkey can complete the MFA challenge with a passkey assertion instead of a TOTP code; `EnforceSessionPolicies` counts it as MFA satisfied | `test_login_challenge_accepts_passkey_assertion` — password → challenge page offers passkey → valid assertion → authenticated, no setup flag, lands in the app | PLT-05 |
| C-05 | A failed, cancelled, or tampered assertion fails with the same generic error as a bad TOTP code — no distinction an attacker can enumerate — and is audited | `test_passkey_challenge_failure_is_generic` — bad signature / wrong credential / user cancellation all produce the generic challenge error and a `mfa.challenge.failed` audit event, mirroring 005 C-03 behavior | PLT-05 |
| C-06 | A user can revoke their own passkeys from the enrollment page (password re-auth required); an org admin who revokes their **last** factor (passkey or TOTP) returns to setup mode at next login | `test_user_can_revoke_passkey` and `test_admin_revoking_last_factor_returns_to_setup_mode` — revoke without password confirmation is refused; with it, the credential is gone and audited; passkey-only admin's next login is setup-mode again | PLT-05 |
| C-07 | The server stores **public keys only**: no private key material, biometric data, or assertion payloads ever reach the database, HTML, JSON, or logs (biometrics never leave the user's device — that is the WebAuthn design, and the leak sentinel proves we keep it that way) | `test_passkey_storage_contains_no_private_material` — leak-sentinel scan of the credentials table dump, enrollment/challenge HTML+JSON, and logs finds no private-key markers, no raw assertion/clientData payloads | — (credential-hygiene law) |
| C-08 | Ceremonies are bound to this origin: assertions generated for a different RP ID / origin are rejected, and challenge values are single-use and short-lived | `test_assertion_from_wrong_origin_is_rejected` and `test_challenge_replay_is_rejected` — cross-origin assertion → generic failure; replaying a consumed challenge → generic failure | PLT-05 |
| C-09 | TOTP is untouched: the complete spec 005 suite passes unmodified, and a user may hold TOTP, passkeys, or both — either factor satisfies a challenge | `test_user_with_both_factors_can_use_either` + full 005 suite green with no edits to its tests | PLT-05 |
| C-10 | The passkey enrollment and challenge screens pass the mobile gate at 390px, same as every 007 screen (standing mobile-first law, 007-D04) | `test_mobile_layout_includes_passkey_screens` — enrollment (both paths) and challenge screens join the 007 mobile-gate screen list; no page overflow, all actions ≥44px | — (007-D04) |
| C-11 | Every passkey lifecycle event is audited append-only: registration, challenge success, challenge failure, revocation | `test_passkey_events_are_audited` — `mfa.passkey.registered`, `mfa.passkey.challenge.succeeded`, `mfa.passkey.challenge.failed`, `mfa.passkey.revoked` rows exist with actor + credential label, never the credential payload | PLT-05 |

## Blueprint anchors

- [D] 001 C-06 (PLT-05) — MFA **required for org admins**; the policy does not change. This spec adds a second acceptable factor beside TOTP + recovery codes; it does not weaken, waive, or reorder the requirement.
- [D] 005-D01 — setup mode is a restricted session governed by an **explicit route allowlist** in `RestrictToTwoFactorSetup`, never a denylist. Passkey ceremony routes join that allowlist; nothing else about the mechanism changes.
- [D] `EnforceSessionPolicies` — the single point where "MFA satisfied" is judged at login (org admin + no confirmed TOTP → setup mode). It learns one new fact — *has confirmed passkey* — through the same predicate shape (`hasEnabledTwoFactorAuthentication()` gains a sibling, not a rewrite).
- [D] 002-D02 `docs/UI_Standards.md` — enrollment and challenge surfaces use `<x-ui.*>` components only, light-only app surfaces.
- [D] 007-D04 — mobile-first is a gating claim (C-10), not an afterthought.
- [D] Append-only audit — passkey events follow the 005 audit naming family (`mfa.*`).
- [P] **Library: Laragear WebAuthn (v5.x)** — verified compatible with this stack (requires PHP ^8.3, illuminate/support 12.*|13.*; we run PHP 8.4 / Laravel 13). It provides the credential model/migrations, ceremony endpoints, and a test-time virtual authenticator path. The alternative, web-auth/webauthn-framework directly, is lower-level and would put ceremony plumbing in our codebase — rejected unless Archaeology finds a Laragear blocker. Council confirms during Archaeology; the brief's claims are written against WebAuthn behavior, not package APIs, so a library swap does not invalidate the verdicts.
- [P] **Second-factor scope only.** In this spec a passkey is used *after* the password, exactly where TOTP sits today. Passwordless (passkey-only, discoverable-credential) sign-in is a different product decision with its own recovery story — see O-01.
- [O] **O-01 Passwordless login.** Deferred. Needs its own brief: discoverable credentials, username-first flows, and what "forgot my passkey" means when there is no password either.
- [O] **O-02 Recovery story for passkey-only admins.** Recovery codes today are a Fortify TOTP feature. If an admin enrolls *only* a passkey and loses the device, what restores them — issue recovery codes at passkey confirmation too, require a second passkey, or fall back to another admin resetting them (the 004 invitation machinery)? Council must decide before Plan; C-03/C-06 verdicts are written to survive any of the three answers, but Plan cannot start without one.
- [O] **O-03 Production RP ID / domain.** WebAuthn credentials are bound to a domain (the RP ID); browsers will not run ceremonies for an IP-literal origin like the current dev URL (`172.235.37.44:3100`). Dev and CI verify ceremonies with a virtual authenticator under a configured test RP ID; **real-device enrollment only becomes possible once Vision Law is served from its production domain.** The RP ID must be configuration (env), never hardcoded, and the production domain decision gates rollout, not this spec's code. Council should name the intended domain if it is already decided.
- [O] **O-04 Attestation policy.** v1 stance proposed: accept any authenticator (platform or roaming), no attestation enforcement — the consumer/enterprise mix we serve makes strict attestation a support burden. A future enterprise tier may require attested hardware keys for admins. Not decided here.
- [O] **O-05 Non-admin requirement.** Whether passkeys (or any MFA) should ever be *required* for non-admin roles stays open, as it was in 005. This spec makes passkeys *available* to every role.

## Security classification

- **Actors:** org_admin without MFA (setup-mode restricted session), any authenticated user (passkey management on the enrollment page), challenged user mid-login (password proven, second factor pending), anonymous (denied everywhere).
- **Data classes touched:** confidential-adjacent (WebAuthn public-key credentials — public keys and credential IDs; **not secret**, but access-controlled as account-security data), confidential (login challenge values — server-side, single-use, short TTL, never rendered after issuance), **never present on the server**: private keys, biometric templates, device unlock secrets (they do not leave the authenticator, by protocol design).
- **Allow events (audit):** `mfa.passkey.registered`, `mfa.passkey.challenge.succeeded`, `mfa.passkey.revoked` — each carrying the user-chosen credential label.
- **Denial events (audit):** `mfa.passkey.challenge.failed` (generic at the surface, reasoned in the audit payload: bad signature / unknown credential / cancelled / replay / wrong origin), revocation refused without password confirmation.
- **Leak sentinels:** no private-key material, raw assertion payloads, or challenge values in HTML, JSON, logs, or serialized models; credential rows never exposed outside the owner's own enrollment page.

## Evidence and fixtures

Canonical fixtures (Archaeology produces `04-fixtures.json`): org_admin with TOTP only, org_admin with passkey only, org_admin with both, attorney with passkey, a second user's passkey (cross-account assertion attempts), a revoked credential, a wrong-origin assertion transcript. All synthetic; ceremonies driven by a virtual authenticator (deterministic keys) so verdicts are hermetic in CI — the same lesson spec 007 paid for: **no test may depend on a real device, daemon, or browser capability that CI lacks.**

## Pre-mortem

- **Likely failure:** RP ID / origin confusion. A passkey registered under one origin is useless under another; if the production domain changes later, every enrolled passkey breaks. Mitigated by O-03: RP ID is env configuration from day one, and rollout waits for the real domain.
- **Likely failure:** the setup-mode allowlist leaks (same risk class as 005's pre-mortem). Mitigated by the 005 pattern: explicit allowlist + a test that walks admin routes in setup mode (C-01).
- **Assumption:** Laragear WebAuthn v5's ceremony endpoints and credential schema fit Fortify's challenge flow without forking the package. Archaeology verifies against the real login pipeline; if it fights us, [P] library choice is revisited at Council — before Plan, not during Execution.
- **Unknown:** real-device behavior on the dev box is untestable until a domain exists (O-03). The verdicts therefore prove protocol behavior hermetically; a manual real-device smoke test on the production domain becomes a Freeze-gate checklist item, not a claim.
- **Support burden:** users on older devices without platform authenticators still have TOTP — the fallback is already built and stays first-class (C-09).

## Non-goals

- Passwordless / passkey-only sign-in (O-01 — future brief).
- SMS or email as factors (decided against: SMS is NIST-restricted and SIM-swap-prone; email is not credited as a true second factor in enterprise review — neither is reopened here).
- SSO / SAML / OIDC (integrations phase; an IdP's MFA will ride along there).
- Removing or demoting TOTP, or changing any 005 screen behavior beyond adding the passkey path.
- Attestation enforcement or hardware-key-only policies (O-04).
- Requiring MFA for non-admin roles (O-05).
