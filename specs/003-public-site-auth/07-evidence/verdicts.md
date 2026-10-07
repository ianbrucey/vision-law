# 003 — Public site + auth pages — Claim verdicts

> Final verdict sweep, T-07 freeze. Full log: `verdicts.log`.
> Branch: `feat/003-public-site-auth`, 2026-10-07.

| Claim | Verdict test(s) | Result |
|---|---|---|
| C-01 | `test_landing_renders_with_all_mockup_sections` | PASS — hero wordmark, nav, trust strip, platform grid, auditability section, testimonial w/ synthetic badge, final CTA, footer, viewport meta |
| C-02 | `test_login_page_renders_for_guests`, `test_login_with_valid_credentials_authenticates` | PASS — GET `/login` 200 w/ email+password fields posting to `/login`; POST valid creds → authenticated + redirect `/` |
| C-03 | `test_register_page_renders_for_guests`, `test_register_creates_user_without_auto_login` | PASS — no org-name field (003-O1 default); POST creates user in resolved org w/ least-privileged role, redirect `/login`, no auto-login |
| C-04 | `test_public_pages_have_no_user_data`, `test_authenticated_users_redirected_off_guest_pages` | PASS — no name/email/org/session strings; signed-in GET `/login\|/register` → redirect |
| C-05 | `test_logo_assets_served` | PASS — `/images/vision-emblem.png`, `/images/vision-wordmark.png` → 200, image content types |
| C-06 | gates | PASS — `php artisan test` 124/1132, `pint --test` 112 files, `phpstan` (level 7) no errors, `npm run build` ok |
| C-07 | mockup approval | PASS — recorded in `05-ui.md`; Ian approved 2026-10-07 |

Architecture doors: 15/15 green incl. the new
`test_door_4_public_views_exempt_from_h1_rule` (T-07 freeze reconciliation).
Responsive (T-06): viewport meta + no fixed-width containers > 100vw; checked
against the phone screenshots.
