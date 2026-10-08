<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Tests\Helpers\FixtureLoader;
use Tests\Helpers\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Spec 008 T-03 verdicts — the passkey login challenge.
 *
 * C-04: a valid assertion inside the challenged session completes the
 *       login with the same post-login state as the TOTP path.
 * C-05: every assertion failure — tampered signature, another user's
 *       credential — is the same generic 422; nobody is signed in.
 * C-06 (login half): an admin whose last factor is revoked returns to
 *       setup mode at the next login.
 * C-08: wrong-origin assertions and replayed assertions are rejected.
 * C-09: a user with both factors can complete the challenge with either.
 * C-11 (challenge half): mfa.passkey.challenge.succeeded / .failed are
 *       audited; auth.login records mfa_used = true.
 *
 * Plus 008-D02 acceptance: the recovery codes issued at passkey
 * confirmation are accepted by the existing challenge POST for a
 * passkey-only user.
 */
class PasskeyChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $role = 'attorney'): User
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $user = User::create([
            'org_id' => $orgId,
            'name' => 'Challenge User',
            'email' => $email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(
            Role::where('org_id', $orgId)->where('name', $role)->firstOrFail()
        );

        return $user;
    }

    private function registerPasskey(User $user, VirtualAuthenticator $authenticator, string $label = 'iPhone'): void
    {
        $this->actingAs($user);

        $options = $this->postJson(route('passkeys.register.options'))->assertOk()->json();
        $this->postJson(
            route('passkeys.register'),
            $authenticator->attest($options) + ['alias' => $label]
        )->assertOk();

        $this->post(route('logout'));
    }

    private function enrollTotp(User $user): string
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(
                collect(range(1, 8))->map(fn () => RecoveryCode::generate())->all()
            )),
        ])->save();

        return $secret;
    }

    /**
     * Password-stage login that must land on the challenge page.
     */
    private function loginToChallenge(User $user): void
    {
        $this->post('/login', [
            'email' => $user->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_login_challenge_accepts_passkey_assertion(): void
    {
        // U-PK-04: passkey-only attorney.
        $user = $this->makeUser('pk-challenge@sterling.test');
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($user, $authenticator);

        $this->loginToChallenge($user);

        // The challenge page offers the passkey ceremony — and no TOTP
        // code form for a passkey-only user.
        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee("Verify it's you", false)
            ->assertSee('Use a passkey')
            ->assertDontSee('6-digit code');

        $options = $this->postJson(route('two-factor.passkey.options'))
            ->assertOk()
            ->json();
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame(
            $authenticator->credentialId(),
            $options['allowCredentials'][0]['id']
        );

        $this->postJson(route('two-factor.passkey.store'), $authenticator->assert($options))
            ->assertOk()
            ->assertJsonPath('redirect', url('/'));

        // Same post-login state as the TOTP path: authenticated, login
        // stamped, counters cleared (the next login starts clean).
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);

        // The credential counter synced to the authenticator's count.
        $this->assertSame(1, (int) DB::table('webauthn_credentials')
            ->where('id', $authenticator->credentialId())->value('counter'));

        // C-11 (challenge half): both audit rows exist; auth.login came
        // from Fortify's completion event with mfa_used = true.
        $this->assertDatabaseHas('audit_events', [
            'event' => 'mfa.passkey.challenge.succeeded',
            'actor_id' => $user->id,
        ]);
        $login = DB::table('audit_events')
            ->where('event', 'auth.login')->where('actor_id', $user->id)
            ->latest('created_at')->first();
        $this->assertNotNull($login);
        $this->assertStringContainsString('"mfa_used": true', (string) $login->payload);
    }

    public function test_passkey_only_user_can_use_recovery_code_at_challenge(): void
    {
        // 008-D02: codes issued at passkey confirmation are accepted by
        // the existing challenge POST — the break-glass works.
        $user = $this->makeUser('pk-breakglass@sterling.test');
        $this->registerPasskey($user, VirtualAuthenticator::make());

        $code = $user->fresh()->recoveryCodes()[0];
        $this->assertNotNull($code);

        $this->loginToChallenge($user);

        $this->post(route('two-factor.login.store'), ['recovery_code' => $code])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // Single use: the code was consumed.
        $this->assertNotContains($code, $user->fresh()->recoveryCodes());

        $this->post(route('logout'));
        $this->loginToChallenge($user);
        $this->post(route('two-factor.login.store'), ['recovery_code' => $code])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_passkey_challenge_failure_is_generic(): void
    {
        $user = $this->makeUser('pk-generic@sterling.test');
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($user, $authenticator);

        $this->loginToChallenge($user);

        // Failure shape 1: tampered signature.
        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $tampered = $this->postJson(
            route('two-factor.passkey.store'),
            $authenticator->assert($options, tamper: true)
        )->assertStatus(422);
        $this->assertGuest();

        // Failure shape 2: a credential that belongs to nobody here.
        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $stranger = VirtualAuthenticator::make();
        $unknown = $this->postJson(
            route('two-factor.passkey.store'),
            $stranger->assert($options)
        )->assertStatus(422);
        $this->assertGuest();

        // Identical surface: same status, same body — nothing probeable.
        $this->assertSame($tampered->getContent(), $unknown->getContent());
        $this->assertSame('{"code":"invalid_passkey"}', $tampered->getContent());

        $this->assertDatabaseHas('audit_events', [
            'event' => 'mfa.passkey.challenge.failed',
            'actor_id' => $user->id,
        ]);
    }

    public function test_assertion_from_wrong_origin_is_rejected(): void
    {
        $user = $this->makeUser('pk-origin@sterling.test');
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($user, $authenticator);

        $this->loginToChallenge($user);

        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $this->postJson(
            route('two-factor.passkey.store'),
            $authenticator->assert($options, originOverride: 'https://evil.example')
        )->assertStatus(422)->assertExactJson(['code' => 'invalid_passkey']);

        $this->assertGuest();
    }

    public function test_challenge_replay_is_rejected(): void
    {
        $user = $this->makeUser('pk-replay@sterling.test');
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($user, $authenticator);

        // First use of the assertion succeeds.
        $this->loginToChallenge($user);
        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $payload = $authenticator->assert($options);
        $this->postJson(route('two-factor.passkey.store'), $payload)->assertOk();
        $this->assertAuthenticatedAs($user);

        // A fresh login issues a fresh challenge; replaying the captured
        // assertion against it fails — challenges are single-use.
        $this->post(route('logout'));
        $this->loginToChallenge($user);
        $this->postJson(route('two-factor.passkey.options'))->assertOk();
        $this->postJson(route('two-factor.passkey.store'), $payload)
            ->assertStatus(422)
            ->assertExactJson(['code' => 'invalid_passkey']);
        $this->assertGuest();
    }

    public function test_user_with_both_factors_can_use_either(): void
    {
        // U-PK-05: TOTP confirmed + passkey registered.
        $user = $this->makeUser('pk-both@sterling.test');
        $secret = $this->enrollTotp($user);
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($user, $authenticator);

        // Path 1: the TOTP code completes the challenge (005 untouched).
        $this->loginToChallenge($user);
        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('Use a passkey')
            ->assertSee('6-digit code');
        $this->post(route('two-factor.login.store'), [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // Path 2: the passkey completes it too.
        $this->post(route('logout'));
        $this->loginToChallenge($user);
        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $this->postJson(route('two-factor.passkey.store'), $authenticator->assert($options))
            ->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_revoking_last_factor_returns_to_setup_mode(): void
    {
        // U-PK-01 variant: passkey-only org admin.
        $admin = $this->makeUser('pk-admin-revoke@sterling.test', 'org_admin');
        $authenticator = VirtualAuthenticator::make();
        $this->registerPasskey($admin, $authenticator);

        // Login via passkey: full session, no setup flag (008 predicate).
        $this->loginToChallenge($admin);
        $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();
        $this->postJson(route('two-factor.passkey.store'), $authenticator->assert($options))
            ->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertEmpty(session(RestrictToTwoFactorSetup::SESSION_KEY));
        $this->get('/admin/invitations')->assertOk();

        // Revoke the only factor.
        $this->post('/user/confirm-password', [
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $this->delete(route('passkeys.destroy', $authenticator->credentialId()))
            ->assertRedirect(route('two-factor.settings'));

        // Next login: back in setup mode. (The redirect + flag ARE the
        // return to setup mode; the restricted-session bounce for such a
        // session is spec 005's C-01 verdict. This sequence stops at the
        // redirect because the test harness does not adopt the session
        // 005's listener regenerates mid-login after a prior session —
        // a pre-existing harness quirk, reproduced with passkey-less
        // admins on the unmodified 005 listener.)
        $this->post(route('logout'));
        $this->post('/login', [
            'email' => $admin->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));
        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));
    }

    public function test_passkey_challenge_requires_challenged_session(): void
    {
        // No password stage, no ceremony: both endpoints refuse with the
        // contract's generic 422 (never an options oracle).
        $this->postJson(route('two-factor.passkey.options'))
            ->assertStatus(422)
            ->assertExactJson(['code' => 'invalid_challenge']);

        $this->postJson(route('two-factor.passkey.store'), [])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'invalid_challenge']);
    }
}
