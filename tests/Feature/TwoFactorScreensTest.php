<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Http\Responses\FailedTwoFactorLoginResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('user/two-factor-qr-code', false);

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
     * C-03: password step → challenge page renders → valid TOTP →
     * authenticated (org admin with confirmed 2FA, U-2FA-02); the
     * recovery-code path consumes the code.
     */
    public function test_challenge_accepts_totp_and_recovery_code(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $google2fa = new Google2FA;

        // Password step → Fortify redirects to the challenge page (005-D02).
        $this->post('/login', [
            'email' => (string) $admin->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertTrue(session()->has('login.id'));

        // The challenge page renders: email shown (not editable), both
        // forms present.
        $page = $this->get(route('two-factor.login'))->assertOk();
        $page->assertSee('Check your authenticator app', false);
        $page->assertSee((string) $admin->email, false);
        $page->assertSee('Verify and sign in', false);
        $page->assertSee('Use recovery code', false);

        // Valid TOTP → authenticated. The code comes from the real
        // generated secret (T-01 lesson: never swap it); the next 30s
        // window keeps it replay-safe.
        $secret = decrypt($admin->two_factor_secret);
        $otp = $google2fa->oathTotp($secret, (int) (time() / 30) + 1);
        $this->post('/two-factor-challenge', ['code' => $otp])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('login.id'));

        // Recovery-code path: fresh challenged session, one unused code.
        $this->post(route('logout'))->assertRedirect('/');
        $this->post('/login', [
            'email' => (string) $admin->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));

        $codes = $admin->refresh()->recoveryCodes();
        $this->assertCount(10, $codes);

        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        // The code is consumed: replaced with a fresh one, not reusable.
        $remaining = $admin->refresh()->recoveryCodes();
        $this->assertCount(10, $remaining);
        $this->assertNotContains($codes[0], $remaining);
    }

    /**
     * C-03: a bad TOTP code, a bad recovery code, and a consumed recovery
     * code all fail with the same generic, non-enumerating message
     * (005-D05) — same key, same text, still a guest.
     */
    public function test_challenge_rejects_invalid_code_generically(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $email = (string) $admin->email;
        $message = FailedTwoFactorLoginResponse::MESSAGE;

        $startChallenge = function () use ($email): void {
            $this->post('/login', [
                'email' => $email,
                'password' => FixtureLoader::DEFAULT_PASSWORD,
            ])->assertRedirect(route('two-factor.login'));
        };

        // Bad TOTP → generic error, still a guest, challenge session intact.
        $startChallenge();
        $this->post('/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors(['code' => $message]);
        $this->assertGuest();
        $this->assertTrue(session()->has('login.id'));

        // Bad recovery code → the SAME key and message (no enumeration).
        $this->post('/two-factor-challenge', ['recovery_code' => 'nope-nope'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors(['code' => $message]);
        $this->assertGuest();

        // A consumed recovery code fails identically: use one, then replay it.
        $codes = $admin->refresh()->recoveryCodes();
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        $this->post(route('logout'))->assertRedirect('/');
        $startChallenge();
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors(['code' => $message]);
        $this->assertGuest();
        // The rendered page shows the single generic message
        // (HTML-escaped). A fresh failure feeds the flash directly into
        // the GET: reading the session via assertSessionHasErrors ages
        // flash data, so the render check needs its own request pair.
        $this->post('/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'));
        $this->get(route('two-factor.login'))->assertOk()
            ->assertSee('That code didn&#039;t work. Try the current code from your app.', false);
    }

    /**
     * U-2FA-05: the challenge page is unreachable without a live
     * challenged-user session — guests and signed-in users alike land on
     * login (no challenge surface without the session).
     */
    public function test_challenge_without_challenged_session_redirects_to_login(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));

        $loader = FixtureLoader::load();
        $viewer = $loader->user('user_viewer');
        $this->actingAs($viewer);
        $this->assertFalse(session()->has('login.id'));
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }
}
