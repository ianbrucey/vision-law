<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Ticket 4 verdict tests — authentication flows, backend only (001-D06: no
 * Blade). Every test exercises HTTP POSTs and asserts status codes,
 * redirects, database state, and mail — never rendered HTML.
 *
 * The HIBP k-anonymity API is faked in setUp: tests never touch the network
 * (001-D07 fail-closed behavior is covered by faking an outage).
 */
class AuthFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stray requests are blocked so a test can never hit the real HIBP
        // API (001-D07). Each test fakes the API exactly once via fakeHibp()
        // — Http::fake() merges stubs, so re-faking mid-test is unreliable.
        Http::preventStrayRequests();
    }

    /**
     * Fake the HIBP k-anonymity API exactly once per test.
     *
     * @param  list<string>  $breached  passwords to report as breached
     */
    private function fakeHibp(array $breached = [], bool $down = false): void
    {
        $stubs = [];

        foreach ($breached as $password) {
            $hash = strtoupper(sha1($password));
            // The API is queried by 5-char hash prefix; stub that exact
            // prefix so only this password reports breached.
            $stubs['https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)] =
                Http::response(substr($hash, 5).":7\n", 200);
        }

        // Fallback for every other prefix: clean (or outage).
        $stubs['https://api.pwnedpasswords.com/range/*'] = $down
            ? Http::response(null, 503)
            : Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:2\n", 200);

        Http::fake($stubs);
    }

    /**
     * C-01: self-registration creates an unverified user; the user cannot
     * log in until the email is verified.
     */
    public function test_registration_requires_email_verification_before_login(): void
    {
        $org = $this->makeOpenOrg();
        $this->fakeHibp();

        Notification::fake();

        $response = $this->postJson('/register', [
            'name' => 'Rita Registrant',
            'email' => 'rita.registrant@example.test',
            'password' => 'Correct-Horse-99-Battery',
        ]);

        $response->assertCreated();
        $this->assertGuest();

        $user = User::where('email', 'rita.registrant@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame((string) $org->getKey(), (string) $user->org_id);
        $this->assertTrue($user->hasRole('viewer'));

        Notification::assertSentTo($user, VerifyEmail::class);

        // Unverified: login refused with the generic error — identical to a
        // wrong password, so verification state is not enumerable.
        $this->postJson('/login', [
            'email' => 'rita.registrant@example.test',
            'password' => 'Correct-Horse-99-Battery',
        ])->assertStatus(422)->assertJson(['code' => 'invalid_credentials']);
        $this->assertGuest();

        // auth.login.failed is audited with the domain digest and no email.
        $failed = AuditEvent::where('event', 'auth.login.failed')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', 'example.test', (string) config('app.key')),
            $failed->payload['email_domain_digest']
        );
        $this->assertArrayNotHasKey('email', $failed->payload);

        // Verify through the signed URL, then login succeeds.
        $verifyUrl = URL::signedRoute('verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]);
        $this->actingAs($user)->getJson($verifyUrl)->assertNoContent();

        // actingAs authenticated the test session; log out so the login
        // attempt below runs as a guest (the /login route is guest-only).
        $this->postJson('/logout')->assertNoContent();

        $this->postJson('/login', [
            'email' => 'rita.registrant@example.test',
            'password' => 'Correct-Horse-99-Battery',
        ])->assertOk()->assertJson(['two_factor' => false]);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('audit_events', ['event' => 'user.created']);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login']);
    }

    /**
     * Duplicate registration emails get a generic message — the account is
     * not enumerable.
     */
    public function test_duplicate_email_registration_returns_generic_message(): void
    {
        $this->makeOpenOrg();
        $this->fakeHibp();

        $payload = [
            'name' => 'Rita Registrant',
            'email' => 'rita.registrant@example.test',
            'password' => 'Correct-Horse-99-Battery',
        ];

        $this->postJson('/register', $payload)->assertCreated();

        $this->postJson('/register', $payload)
            ->assertStatus(422)
            ->assertJsonPath(
                'details.email.0',
                'If this email is available, a verification link was sent.'
            );

        $this->assertSame(1, User::where('email', 'rita.registrant@example.test')->count());
    }

    /**
     * C-02: weak passwords, breached passwords, and breached-check outages
     * are all rejected.
     */
    public function test_weak_and_breached_passwords_rejected(): void
    {
        $this->makeOpenOrg();

        $breachedPassword = 'Breach-Me-Please-99';
        $this->fakeHibp([$breachedPassword]);

        // Weak: under 12 characters.
        $this->postJson('/register', [
            'name' => 'Wally Weak',
            'email' => 'wally@example.test',
            'password' => 'short1!',
        ])->assertStatus(422)
            ->assertJson(['code' => 'validation'])
            ->assertJsonStructure(['details' => ['password']]);

        // Breached: 12+ characters but present in the breach corpus (faked).
        $this->postJson('/register', [
            'name' => 'Betty Breached',
            'email' => 'betty@example.test',
            'password' => $breachedPassword,
        ])->assertStatus(422)
            ->assertJsonPath(
                'details.password.0',
                'This password has appeared in a data breach. Please choose a different password.'
            );

        $this->assertDatabaseMissing('users', ['email' => 'wally@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'betty@example.test']);
    }

    /**
     * 001-D07: the breached-password check FAILS CLOSED — when the
     * k-anonymity API is unreachable, the password attempt is rejected with
     * a retryable error instead of being let through.
     */
    public function test_breached_password_check_fails_closed_when_api_unreachable(): void
    {
        $this->makeOpenOrg();
        $this->fakeHibp(down: true);

        $this->postJson('/register', [
            'name' => 'Uma Unavailable',
            'email' => 'uma@example.test',
            'password' => 'Another-Fine-Password-99',
        ])->assertStatus(422)
            ->assertJsonPath(
                'details.password.0',
                'The password safety check is temporarily unavailable. Please try again.'
            );

        $this->assertDatabaseMissing('users', ['email' => 'uma@example.test']);
    }

    /**
     * C-03: five failures trigger a 15-minute lockout with exponential
     * backoff; success clears the counters; lockouts are audited.
     */
    public function test_brute_force_lockout_after_five_failures(): void
    {
        $fixtures = FixtureLoader::load();
        $user = $fixtures->user('user_viewer');
        $email = (string) $user->email;

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password-1'])
                ->assertStatus(422)
                ->assertJson(['code' => 'invalid_credentials']);
        }

        // The 5th failure locks the account: 429 with retry_after = 900.
        $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password-1'])
            ->assertStatus(429)
            ->assertJson(['code' => 'locked_out', 'retry_after' => 900]);

        // The correct password is still refused while locked out.
        $this->postJson('/login', ['email' => $email, 'password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertStatus(429)
            ->assertJson(['code' => 'locked_out']);
        $this->assertGuest();

        $this->assertSame(5, AuditEvent::where('event', 'auth.login.failed')->count());
        $this->assertGreaterThanOrEqual(1, AuditEvent::where('event', 'auth.login.locked_out')->count());

        // Unknown email: identical generic response (no enumeration).
        $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-1'])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_credentials']);

        // Exponential backoff: once the lockout expires, the next cycle
        // doubles to 30 minutes.
        $this->travel(901)->seconds();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password-1'])
                ->assertStatus(422);
        }

        $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password-1'])
            ->assertStatus(429)
            ->assertJson(['code' => 'locked_out', 'retry_after' => 1800]);

        $this->travelBack();

        // Success clears the counters: after the lockout state is gone, a
        // good login works and a single bad try is a plain 422 again.
        Cache::flush();

        $this->postJson('/login', ['email' => $email, 'password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertOk()
            ->assertJson(['two_factor' => false]);
        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertNoContent();

        $this->postJson('/login', ['email' => $email, 'password' => 'wrong-password-1'])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_credentials']);
    }

    /**
     * C-04: password reset uses generic responses (no enumeration), a
     * single-use 1-hour token, and revokes ALL of the user's sessions.
     */
    public function test_password_reset_revokes_sessions_and_uses_generic_responses(): void
    {
        $fixtures = FixtureLoader::load();
        $this->fakeHibp();
        $user = $fixtures->user('user_viewer');
        $other = $fixtures->user('user_paralegal');
        $email = (string) $user->email;

        // Seed session rows directly (the test env uses the database
        // session driver, same as production): the revocation listener deletes by user_id regardless.
        DB::table('sessions')->insert([
            ['id' => 'sess-a', 'user_id' => $user->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sess-b', 'user_id' => $user->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sess-c', 'user_id' => $other->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()],
        ]);

        Notification::fake();

        // Unknown email: generic success, no notification sent, no enumeration.
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk();
        Notification::assertNothingSent();

        // Known email: byte-identical generic success, notification sent to the owner.
        $known = $this->postJson('/forgot-password', ['email' => $email])->assertOk();
        $this->assertSame($unknown->json('message'), $known->json('message'));

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        // Reset with the token.
        $newPassword = 'Fresh-Start-Password-99';
        $reset = $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => $newPassword,
        ])->assertOk();

        // Leak sentinel: the token never appears in the response.
        $this->assertStringNotContainsString((string) $token, (string) $reset->getContent());

        // ALL of the user's sessions are revoked; the other user's survive.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->getKey())->count());

        // Single-use: the token is consumed.
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'Another-Fresh-One-99',
        ])->assertStatus(422);

        // The new password works; the old one does not.
        $this->postJson('/login', ['email' => $email, 'password' => $newPassword])
            ->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/logout')->assertNoContent();
        $this->postJson('/login', ['email' => $email, 'password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertStatus(422);

        // Audited with the revoked-session count.
        $event = AuditEvent::where('event', 'auth.password.reset')->firstOrFail();
        $this->assertSame(2, $event->payload['revoked_count']);
        $this->assertSame((string) $user->getKey(), (string) $event->actor_id);
    }

    /**
     * C-06: TOTP enrollment is confirmed by a valid code; login challenges
     * block without one; 8 backup codes; disabling needs password re-auth.
     */
    public function test_mfa_challenge_blocks_login_without_valid_code(): void
    {
        $fixtures = FixtureLoader::load();
        $user = $fixtures->user('user_paralegal');
        $email = (string) $user->email;

        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($user);

        // Disabling/enabling needs password re-auth (confirmPassword: true).
        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertSuccessful();

        // Enroll: secret set, 8 backup codes, NOT yet confirmed.
        $enable = $this->postJson('/user/two-factor-authentication')->assertOk();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->recoveryCodes());

        // Leak sentinel: the enable response carries no secret.
        $this->assertStringNotContainsString(
            (string) decrypt($user->two_factor_secret),
            (string) $enable->getContent()
        );

        // Swap in a known secret so TOTP codes are computable in the test.
        $user->forceFill(['two_factor_secret' => encrypt($secret)])->save();

        // A wrong confirmation code does not confirm enrollment.
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertStatus(422);
        $this->assertNull($user->refresh()->two_factor_confirmed_at);

        // A valid code confirms enrollment.
        $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk();
        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.mfa.enabled']);

        $this->postJson('/logout')->assertNoContent();

        // Login with password alone → challenged, not authenticated.
        $this->postJson('/login', ['email' => $email, 'password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertOk()
            ->assertJson(['two_factor' => true]);
        $this->assertGuest();

        // Wrong challenge code → still a guest.
        $this->postJson('/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(422);
        $this->assertGuest();

        // Fortify replay-protects TOTP codes: the challenge code must differ
        // from the confirm code, so use the next 30s window's OTP. Window=1
        // verification accepts it whether or not a window boundary passes
        // mid-test (pragmarx uses raw time(), so travel() cannot shift it).
        $nextWindowOtp = $google2fa->oathTotp($secret, (int) (time() / 30) + 1);

        // Valid challenge code → authenticated, audited with mfa_used.
        $this->postJson('/two-factor-challenge', [
            'code' => $nextWindowOtp,
        ])->assertNoContent();
        $this->assertAuthenticatedAs($user);

        $loginEvent = AuditEvent::where('event', 'auth.login')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertTrue($loginEvent->payload['mfa_used']);

        // Disabling without a FRESH password confirmation → 423.
        session()->forget('auth.password_confirmed_at');
        $this->deleteJson('/user/two-factor-authentication')->assertStatus(423);
        $this->assertNotNull($user->refresh()->two_factor_secret);

        // With password re-auth, disabling works and is audited.
        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertSuccessful();
        $this->deleteJson('/user/two-factor-authentication')->assertOk();
        $this->assertNull($user->refresh()->two_factor_secret);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.mfa.disabled']);
    }

    /**
     * Leak sentinels (00-brief.md): password hashes, reset tokens, TOTP
     * secrets, and backup codes never appear in responses, audit payloads,
     * or logs for these flows.
     */
    public function test_auth_flows_leak_no_privileged_data(): void
    {
        $fixtures = FixtureLoader::load();
        $user = $fixtures->user('user_viewer');

        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $codes = ['alpha-1', 'bravo-2', 'charlie-3'];
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $responses = [];

        // Login challenge flow (wrong code, then right code).
        $responses[] = $this->postJson('/login', [
            'email' => (string) $user->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $responses[] = $this->postJson('/two-factor-challenge', ['code' => '000000']);
        $responses[] = $this->postJson('/two-factor-challenge', [
            'code' => $google2fa->getCurrentOtp($secret),
        ]);

        // Failed login.
        $responses[] = $this->postJson('/logout');
        $responses[] = $this->postJson('/login', [
            'email' => (string) $user->email,
            'password' => 'wrong-password-1',
        ]);

        $hash = (string) $user->refresh()->password;

        foreach ($responses as $response) {
            $content = (string) $response->getContent();
            $this->assertStringNotContainsString($hash, $content, 'password hash leaked in response');
            $this->assertStringNotContainsString($secret, $content, 'TOTP secret leaked in response');

            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $content, 'backup code leaked in response');
            }
        }

        // No audit payload may carry privileged KEYS (the blocklist enforces
        // this at write time; this asserts it held across every flow above).
        foreach (AuditEvent::pluck('payload') as $payload) {
            $this->assertNoPrivilegedKeys((array) $payload);
        }

        // Logs carry no privileged VALUES from these flows.
        $logFile = storage_path('logs/laravel.log');

        if (is_file($logFile)) {
            $log = (string) file_get_contents($logFile);
            $this->assertStringNotContainsString($secret, $log, 'TOTP secret leaked in logs');

            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $log, 'backup code leaked in logs');
            }
        }
    }

    /**
     * 001-D08: the production hashing posture is Argon2id at 64 MiB / 3
     * iterations / 1 thread. The test env deliberately weakens this (see
     * phpunit.xml) — this test asserts the config FILE defaults, i.e. what
     * production gets when the env overrides are absent.
     */
    public function test_production_hashing_config_defaults_are_strong(): void
    {
        $keys = ['ARGON2ID_MEMORY', 'ARGON2ID_TIME', 'ARGON2ID_THREADS'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            /** @var array{driver: string, argon: array{memory: int, time: int, threads: int}} $config */
            $config = require base_path('config/hashing.php');
        } finally {
            foreach ($saved as $key => [$env, $e, $s]) {
                if ($env !== false && $env !== null) {
                    putenv("{$key}={$env}");
                } else {
                    putenv($key);
                }

                if ($e !== null) {
                    $_ENV[$key] = $e;
                }

                if ($s !== null) {
                    $_SERVER[$key] = $s;
                }
            }
        }

        $this->assertSame('argon2id', $config['driver']);
        $this->assertGreaterThanOrEqual(65536, $config['argon']['memory']);
        $this->assertGreaterThanOrEqual(3, $config['argon']['time']);
        $this->assertGreaterThanOrEqual(1, $config['argon']['threads']);
    }

    /**
     * Load fixtures and open the sterling org for self-registration.
     */
    private function makeOpenOrg(): Organization
    {
        $fixtures = FixtureLoader::load();
        $org = $fixtures->org('org_sterling');
        $org->update(['settings' => ['registration_mode' => 'open']]);

        return $org;
    }

    /**
     * Recursively assert no payload key matches the privileged blocklist.
     *
     * @param  array<mixed>  $payload
     */
    private function assertNoPrivilegedKeys(array $payload, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key)) {
                foreach (['password', 'secret', 'token', 'hash', 'recovery'] as $pattern) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        $pattern,
                        $key,
                        "Privileged key '{$keyPath}' in audit payload"
                    );
                }
            }

            if (is_array($value)) {
                $this->assertNoPrivilegedKeys($value, $keyPath);
            }
        }
    }

    /**
     * Unknown-email login failures are audited against the system org
     * (001-D13): never skipped, never misattributed, no enumeration.
     */
    public function test_unknown_email_login_failure_audited_under_system_org(): void
    {
        $this->postJson('/login', ['email' => 'nobody@nowhere.test', 'password' => 'wrong-password-1'])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_credentials']);

        $event = AuditEvent::query()->where('event', 'auth.login.failed')->sole();
        $this->assertSame(Organization::SYSTEM_ID, (string) $event->org_id);
        $this->assertNull($event->actor_id);

        $payload = $event->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayHasKey('email_domain_digest', $payload);
    }

    /**
     * A new user registering with a matter-scoped invitation token gets the
     * matter grant at registration (T-05 follow-up: accept() only covered
     * existing users).
     */
    public function test_invited_new_user_registration_creates_matter_grant(): void
    {
        $fixtures = FixtureLoader::load();
        $this->fakeHibp();
        Notification::fake();

        $org = $fixtures->org('org_sterling');
        $admin = $fixtures->user('user_admin');
        $matter = $fixtures->matter('matter_001');

        $token = Str::random(64);
        Invitation::create([
            'org_id' => $org->getKey(),
            'email' => 'grant.invitee@sterling.test',
            'token_hash' => hash('sha256', $token),
            'role' => 'viewer',
            'matter_id' => $matter->getKey(),
            'invited_by' => $admin->getKey(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson('/register', [
            'name' => 'Grant Invitee',
            'email' => 'grant.invitee@sterling.test',
            'password' => 'Correct-Horse-99-Battery',
            'invitation_token' => $token,
        ])->assertCreated();

        $user = User::where('email', 'grant.invitee@sterling.test')->firstOrFail();
        $this->assertDatabaseHas('matter_grants', [
            'matter_id' => (string) $matter->getKey(),
            'user_id' => (string) $user->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'matter.grant.created',
            'org_id' => (string) $org->getKey(),
        ]);
    }
}
