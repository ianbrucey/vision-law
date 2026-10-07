<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 005 T-01 verdicts — setup-mode session + enrollment view.
 *
 * C-01: an org admin without enrolled 2FA gets a restricted setup-mode
 * session (redirect to enrollment, not 403); every admin route 302s to
 * enrollment in setup mode; the flag is absent for enrolled users.
 * C-02: enrollment via UI (enable → QR → confirm with valid TOTP) sets
 * two_factor_confirmed_at, clears the setup flag, and shows the recovery
 * codes exactly once; a subsequent login has no setup flag.
 * C-06: a non-admin without 2FA logs in normally — no flag, no redirect —
 * and the enrollment page renders as an optional control.
 *
 * T-03 verdicts (this ticket):
 * C-04: recovery codes render exactly once after confirm AND after
 * regeneration; later visits show status only; regeneration invalidates the
 * old set.
 * C-05: disabling 2FA requires password re-authentication — the DELETE is
 * refused without a recent confirmation and the confirm-password page makes
 * the existing Fortify gate completable via UI.
 * C-07: the TOTP secret / otpauth URIs / recovery-code values never appear
 * in enrollment HTML (any state), the confirm-password page, or logs.
 */
class TwoFactorScreensTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnenrolledOrgAdmin(string $email): User
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $admin = User::create([
            'org_id' => $orgId,
            'name' => 'Setup Admin',
            'email' => $email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole(
            Role::where('org_id', $orgId)->where('name', 'org_admin')->firstOrFail()
        );

        $this->assertNull($admin->two_factor_secret);

        return $admin;
    }

    /**
     * C-01: login as an unenrolled org_admin returns a redirect to the
     * enrollment page (not 403); the session carries the setup-mode flag;
     * admin routes 302 to enrollment in setup mode; enrolled users get no flag.
     */
    public function test_org_admin_without_2fa_gets_setup_mode_session(): void
    {
        $admin = $this->makeUnenrolledOrgAdmin('setupless@sterling.test');

        $this->post('/login', [
            'email' => 'setupless@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));

        // Still authenticated — the session is restricted, not destroyed.
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        $this->assertDatabaseHas('audit_events', [
            'event' => 'auth.login.2fa_enrollment_required',
        ]);

        // The enrollment page renders with the restricted-session banner.
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('Restricted session', false);

        // Spot check: an admin route 302s to enrollment in setup mode.
        $this->get('/admin/invitations')
            ->assertRedirect(route('two-factor.settings'));

        // Allowlist probe: EVERY admin route 302s to enrollment in setup mode.
        $enrollmentUrl = route('two-factor.settings');
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            $params = [];
            foreach ($route->parameterNames() as $param) {
                $params[$param] = (string) Str::uuid();
            }
            $url = route($name, $params);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = match ($method) {
                    'GET' => $this->get($url),
                    'POST' => $this->post($url),
                    'PUT' => $this->put($url),
                    'PATCH' => $this->patch($url),
                    'DELETE' => $this->delete($url),
                    default => $this->fail("Unexpected method {$method} on route {$name}"),
                };

                $response->assertRedirect(
                    $enrollmentUrl,
                    "Setup-mode session reached {$method} {$name} instead of redirecting to enrollment."
                );
            }
        }

        // Allowlisted routes still work in setup mode (Fortify's QR
        // endpoint answers 200 with an empty body when no secret exists).
        $this->get(route('two-factor.qr-code'))->assertOk();
        $this->post(route('logout'))->assertRedirect('/');

        // The flag is absent for fully-enrolled users: the fixture org admin
        // (confirmed 2FA) goes to the challenge, never to setup mode.
        $loader = FixtureLoader::load();
        $enrolled = $loader->user('user_admin');
        $this->postJson('/login', [
            'email' => (string) $enrolled->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertOk()->assertJson(['two_factor' => true]);
        $this->assertFalse((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));
    }

    /**
     * C-02: the org admin enrolls via the UI — enable → QR → confirm with a
     * valid TOTP — then holds a full session; the codes show exactly once;
     * a subsequent login has no setup flag.
     */
    public function test_org_admin_enrolls_via_ui_and_logs_in_fully(): void
    {
        $admin = $this->makeUnenrolledOrgAdmin('enroller@sterling.test');
        $google2fa = new Google2FA;

        // Login → setup-mode redirect.
        $this->post('/login', [
            'email' => 'enroller@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));
        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        // Start setup (from the enrollment page, as the UI does — Fortify's
        // enable response redirects back()).
        $this->get(route('two-factor.settings'))->assertOk();
        $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.settings'));
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertCount(10, $admin->recoveryCodes());

        // The QR renders for the pending secret.
        $this->get(route('two-factor.qr-code'))->assertOk();
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('user/two-factor-qr-code.svg', false);

        // TOTP codes are computed from the real generated secret (decrypt
        // the column — never swap it: the guard caches its user instance
        // across test requests, so a swapped secret would go stale).
        $secret = decrypt($admin->refresh()->two_factor_secret);

        // A wrong code does not confirm.
        $this->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.settings'));
        $this->assertNull($admin->refresh()->two_factor_confirmed_at);
        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        // A valid code confirms: redirect to enrollment, flag cleared,
        // mfa.enrolled audited.
        $this->post(route('two-factor.confirm'), [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertRedirect(route('two-factor.settings'));

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertFalse((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));
        $this->assertDatabaseHas('audit_events', ['event' => 'mfa.enrolled']);

        // Once-display: the next GET shows the 10 codes …
        $codes = $admin->recoveryCodes();
        $this->assertCount(10, $codes);
        $page = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($codes as $code) {
            $page->assertSee($code, false);
        }

        // … a second visit shows status only, never the codes.
        $again = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($codes as $code) {
            $again->assertDontSee($code, false);
        }
        $again->assertSee('Active', false);

        // Full session now: admin routes are reachable.
        $this->get('/admin/invitations')->assertOk();

        // Subsequent login: password step → challenge → valid TOTP → fully
        // authenticated, with no setup flag. Fortify replay-protects TOTP
        // codes, so the challenge uses the next 30s window's OTP.
        $this->post(route('logout'))->assertRedirect('/');

        $this->postJson('/login', [
            'email' => 'enroller@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertOk()->assertJson(['two_factor' => true]);
        $this->assertGuest();

        $nextWindowOtp = $google2fa->oathTotp($secret, (int) (time() / 30) + 1);
        $this->postJson('/two-factor-challenge', ['code' => $nextWindowOtp])
            ->assertNoContent();
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));
    }

    /**
     * C-06: a non-admin without 2FA logs in normally — no flag, no
     * redirect — and the enrollment page renders as an optional control.
     */
    public function test_non_admin_without_2fa_logs_in_normally(): void
    {
        $loader = FixtureLoader::load();
        $attorney = $loader->user('user_attorney_granted');
        $this->assertNull($attorney->two_factor_secret);

        $this->post('/login', [
            'email' => (string) $attorney->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($attorney);
        $this->assertFalse((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        // The enrollment page renders as an optional control — no
        // restricted-session banner.
        $page = $this->get(route('two-factor.settings'))->assertOk();
        $page->assertSee('Set up two-factor authentication', false);
        $page->assertDontSee('Restricted session', false);
    }

    /**
     * C-04: recovery codes render exactly once — after confirm AND after
     * regeneration. A second visit shows status only; regenerating
     * invalidates the old set immediately. Audits mfa.enrolled and
     * mfa.recovery_codes.regenerated.
     */
    public function test_recovery_codes_shown_once_after_confirm(): void
    {
        $admin = $this->makeUnenrolledOrgAdmin('codesonce@sterling.test');
        $google2fa = new Google2FA;

        // Login -> setup mode; enable; confirm with a valid TOTP.
        $this->post('/login', [
            'email' => 'codesonce@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));

        // From the enrollment page, as the UI does (Fortify's enable
        // response redirects back()).
        $this->get(route('two-factor.settings'))->assertOk();
        $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.settings'));

        $secret = decrypt($admin->refresh()->two_factor_secret);
        $this->post(route('two-factor.confirm'), [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertRedirect(route('two-factor.settings'));

        $this->assertDatabaseHas('audit_events', ['event' => 'mfa.enrolled']);

        // Once-display after confirm: the 10 codes render ...
        $codes = $admin->refresh()->recoveryCodes();
        $this->assertCount(10, $codes);
        $page = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($codes as $code) {
            $page->assertSee($code, false);
        }

        // ... a second visit shows status only, never the codes.
        $again = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($codes as $code) {
            $again->assertDontSee($code, false);
        }
        $again->assertSee('Active', false);

        // Regenerate: the new set renders exactly once on the next visit.
        // (The regenerate POST sits behind password.confirm; the setup-mode
        // login proved the password seconds ago, inside the timeout.)
        $this->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('two-factor.settings'));
        $this->assertDatabaseHas('audit_events', ['event' => 'mfa.recovery_codes.regenerated']);

        $newCodes = $admin->refresh()->recoveryCodes();
        // Fortify's regenerate action mints 8 codes (the app's 10-code
        // override covers enrollment only) -- the verdict is the
        // once-display and the invalidation, not the count.
        $this->assertCount(8, $newCodes);
        $this->assertNotSame($codes, $newCodes, 'Regeneration must invalidate the old set.');

        $regen = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($newCodes as $code) {
            $regen->assertSee($code, false);
        }

        // ... and never again: neither the new set nor the old one renders.
        $final = $this->get(route('two-factor.settings'))->assertOk();
        foreach ($newCodes as $code) {
            $final->assertDontSee($code, false);
        }
        foreach ($codes as $code) {
            $final->assertDontSee($code, false);
        }
        $final->assertSee('Active', false);
    }

    /**
     * C-05: disabling 2FA requires password re-authentication. The DELETE
     * without a recent confirmation is refused (302 to the confirm-password
     * page); a wrong password does not confirm; the right password completes
     * the existing Fortify gate via UI, and the disable then succeeds and
     * is audited (mfa.disabled).
     */
    public function test_disabling_2fa_requires_password_confirmation(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin')->fresh();
        $this->assertTrue($admin->hasEnabledTwoFactorAuthentication());

        $this->actingAs($admin);

        // Visit the enrollment page first so the "previous URL" is
        // deterministic for the redirects below.
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('Active', false);

        // 1. DELETE without a recent password confirmation is refused: the
        // password.confirm gate redirects to the confirm-password page.
        $this->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNotNull($admin->refresh()->two_factor_secret);
        $this->assertDatabaseMissing('audit_events', ['event' => 'mfa.disabled']);

        // 2. The gate is completable via UI: the confirm-password page
        // renders and its form posts to Fortify's existing endpoint.
        $this->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Confirm your password', false)
            ->assertSee(route('password.confirm.store'), false);

        // 3. A wrong password does not confirm — back to the form with the
        // generic error; 2FA stays enabled.
        $this->post(route('password.confirm.store'), ['password' => 'Wrong-Password-000'])
            ->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('The provided password was incorrect.', false);
        $this->assertNotNull($admin->refresh()->two_factor_secret);
        $this->assertDatabaseMissing('audit_events', ['event' => 'mfa.disabled']);

        // 4. The right password completes the gate: the confirmation is
        // recorded and Fortify sends the user back to the page that
        // triggered the gate (the enrollment page).
        $this->post(route('password.confirm.store'), ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertRedirect(route('two-factor.settings'));
        $this->assertNotEmpty(session('auth.password_confirmed_at'));

        // 5. With a fresh confirmation, the disable succeeds and is audited.
        $this->delete(route('two-factor.disable'))->assertRedirect();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());
        $this->assertDatabaseHas('audit_events', ['event' => 'mfa.disabled']);

        // The enrollment page is back to the fresh state.
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('Set up two-factor authentication', false);
    }

    /**
     * QR follow-up (T-03): Fortify's two-factor.qr-code returns JSON, not an
     * image, so the enrollment <img> points at the new two-factor.qr-image
     * endpoint, which serves Fortify's QR SVG bytes as image/svg+xml. 404
     * when there is no pending secret; the secret never lands in page HTML.
     */
    public function test_qr_image_endpoint_serves_svg_bytes(): void
    {
        $admin = $this->makeUnenrolledOrgAdmin('qrimg@sterling.test');

        $this->post('/login', [
            'email' => 'qrimg@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));

        // No pending secret yet: nothing to serve.
        $this->get(route('two-factor.qr-image'))->assertNotFound();

        // From the enrollment page, as the UI does (Fortify's enable
        // response redirects back()).
        $this->get(route('two-factor.settings'))->assertOk();
        $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.settings'));

        // The enrollment page references the image endpoint ...
        $page = $this->get(route('two-factor.settings'))->assertOk();
        $page->assertSee('user/two-factor-qr-code.svg', false);

        // ... which serves Fortify's QR SVG bytes as a real image.
        $img = $this->get(route('two-factor.qr-image'))->assertOk();
        $this->assertStringStartsWith(
            'image/svg+xml',
            (string) $img->headers->get('Content-Type'),
            'The QR endpoint must serve image bytes, not JSON.'
        );
        $this->assertStringContainsString('<svg', (string) $img->getContent());

        // The secret / otpauth bytes travel in the image response only --
        // never in the enrollment page's HTML source (005-D04).
        $secret = decrypt($admin->refresh()->two_factor_secret);
        $html = $page->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString('otpauth://', $html);
    }

    /**
     * A rejected challenge code is audited as mfa.challenge.failed —
     * generic, with no code detail in the audit payload — and the 422 body
     * stays generic (no enumeration of which factor failed).
     */
    public function test_failed_challenge_is_audited_generically(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin')->fresh();
        $this->assertTrue($admin->hasEnabledTwoFactorAuthentication());

        // Password step: the login is held for the challenge.
        $this->postJson('/login', [
            'email' => (string) $admin->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertOk()->assertJson(['two_factor' => true]);
        $this->assertGuest();

        // A bad code fails generically.
        $failed = $this->postJson('/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(422);
        $this->assertSame('validation', $failed->json('code'));
        $this->assertGuest();

        // Audited as mfa.challenge.failed, with no code detail logged.
        $this->assertDatabaseHas('audit_events', ['event' => 'mfa.challenge.failed']);
        $row = DB::table('audit_events')
            ->where('event', 'mfa.challenge.failed')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('000000', (string) $row->payload);

        // The generic body carries no privileged strings either.
        $this->assertStringNotContainsString('otpauth://', $failed->getContent());
    }

    /**
     * C-07: the plaintext TOTP secret, any otpauth:// URI, and recovery-code
     * values never appear in enrollment HTML (fresh, setup-mode, active, and
     * once-display states), in the confirm-password page, or in logs.
     * Recovery codes appear ONLY in the one-time display.
     *
     * The challenge view belongs to Ticket 2's branch: the sentinel covers
     * it defensively (scanned when present) but does not block on it.
     */
    public function test_totp_secret_never_leaks_in_ui(): void
    {
        $logRecords = [];
        Log::listen(function (MessageLogged $event) use (&$logRecords): void {
            $logRecords[] = $event->message.' '.json_encode($event->context);
        });

        // --- setup mode with a pending secret: org admin enrolls ---
        // (web login first: a later actingAs would trip the login route's
        // guest middleware, and a web logout invalidates the session the
        // next login needs.)
        $admin = $this->makeUnenrolledOrgAdmin('leakprobe@sterling.test');
        $google2fa = new Google2FA;

        $this->post('/login', [
            'email' => 'leakprobe@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));
        // From the enrollment page, as the UI does (Fortify's enable
        // response redirects back()).
        $this->get(route('two-factor.settings'))->assertOk();
        $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.settings'));

        $admin->refresh();
        $secret = decrypt($admin->two_factor_secret);
        $encryptedSecret = (string) $admin->two_factor_secret;
        $encryptedCodes = (string) $admin->two_factor_recovery_codes;
        $codes = $admin->recoveryCodes();
        $this->assertCount(10, $codes);

        $setupHtml = $this->get(route('two-factor.settings'))->assertOk()->getContent();

        // --- confirm, then the once-display, then the active state ---
        $this->post(route('two-factor.confirm'), [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertRedirect(route('two-factor.settings'));

        $onceHtml = $this->get(route('two-factor.settings'))->assertOk()->getContent();
        $activeHtml = $this->get(route('two-factor.settings'))->assertOk()->getContent();
        $this->assertStringContainsString('Active', $activeHtml);

        // --- fresh state + confirm-password page as the attorney ---
        // (actingAs last: the login flow itself is T-01's C-06; what
        // matters for the sentinel is the rendered HTML.)
        $loader = FixtureLoader::load();
        $attorney = $loader->user('user_attorney_granted');
        $this->assertNull($attorney->two_factor_secret);
        $this->actingAs($attorney);

        $freshHtml = $this->get(route('two-factor.settings'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Set up two-factor authentication', $freshHtml,
            'The fresh-state capture must render the attorney view, not the admin view.'
        );
        $confirmHtml = $this->get(route('password.confirm'))->assertOk()->getContent();

        // Sentinels that must NEVER render outside the one-time display.
        $neverAnywhere = [
            'TOTP secret' => $secret,
            'otpauth URI' => 'otpauth://',
            'encrypted two_factor_secret column' => $encryptedSecret,
            'encrypted two_factor_recovery_codes column' => $encryptedCodes,
        ];
        foreach ([
            'fresh enrollment HTML' => $freshHtml,
            'setup-mode enrollment HTML' => $setupHtml,
            'confirm-password HTML' => $confirmHtml,
            'active enrollment HTML' => $activeHtml,
        ] as $context => $html) {
            foreach ($neverAnywhere as $label => $sentinel) {
                $this->assertStringNotContainsString(
                    $sentinel, $html, "Leak: {$label} present in {$context}"
                );
            }
            foreach ($codes as $code) {
                $this->assertStringNotContainsString(
                    $code, $html, "Leak: recovery code present in {$context}"
                );
            }
        }

        // The one-time display DOES show the codes (that is its purpose) --
        // but still never the secret, an otpauth URI, or encrypted columns.
        foreach ($codes as $code) {
            $this->assertStringContainsString($code, $onceHtml, 'The once-display must show the codes.');
        }
        foreach ($neverAnywhere as $label => $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel, $onceHtml, "Leak: {$label} present in the once-display HTML"
            );
        }

        // Logs: no secret, no code, no otpauth URI anywhere.
        $logBlob = implode("\n", $logRecords);
        $this->assertStringNotContainsString($secret, $logBlob, 'Leak: TOTP secret in logs');
        $this->assertStringNotContainsString('otpauth://', $logBlob, 'Leak: otpauth URI in logs');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $logBlob, 'Leak: recovery code in logs');
        }

        // Defensive: Ticket 2's challenge view is scanned when present, but
        // this verdict does not block on it.
        $challengeView = resource_path('views/auth/two-factor-challenge.blade.php');
        if (file_exists($challengeView)) {
            $source = (string) file_get_contents($challengeView);
            $this->assertStringNotContainsString('otpauth://', $source, 'Leak: otpauth URI in challenge view source');
            $this->assertStringNotContainsString('two_factor_secret', $source, 'Leak: secret column reference in challenge view source');
            $this->assertStringNotContainsString('twoFactorQrCodeSvg', $source, 'Leak: QR SVG helper in challenge view source');
            $this->assertStringNotContainsString($secret, $source, 'Leak: TOTP secret in challenge view source');
        }
    }
}
