<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 005 addendum (decided by Ian Bruce, 2026-10-15): accounts on the
 * TWO_FACTOR_EXEMPT_EMAILS allowlist (config fortify.two_factor_exempt_emails)
 * skip the per-login two-factor challenge.
 *
 * Verdicts:
 * - An exempt, confirmed-2FA user logs in with a password alone and lands
 *   on /home — no challenge is issued (matching is case-insensitive).
 * - A non-exempt confirmed-2FA user still gets the challenge (regression
 *   guard for the spec 005 behavior).
 * - The exemption never bypasses credential validation: a wrong password
 *   for the exempt email fails exactly like any other bad login.
 * - With an empty allowlist the exemption is inert — even the account the
 *   production allowlist names is challenged — proving the behavior comes
 *   from configuration, not identity.
 */
class TwoFactorExemptionTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = FixtureLoader::load();
    }

    private function makeConfirmedTwoFactorUser(string $email, bool $orgAdmin = false): User
    {
        $orgId = $this->loader->id('org_sterling');

        $user = User::create([
            'org_id' => $orgId,
            'name' => 'Exemption Subject',
            'email' => $email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['alpha-1', 'bravo-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        if ($orgAdmin) {
            $user->assignRole(
                Role::where('org_id', $orgId)->where('name', 'org_admin')->firstOrFail()
            );
        }

        return $user;
    }

    public function test_exempt_confirmed_user_skips_challenge_and_lands_on_home(): void
    {
        config(['fortify.two_factor_exempt_emails' => ['ian.b@justicequest.pro']]);

        // The production-shaped case: an org admin with confirmed 2FA.
        $user = $this->makeConfirmedTwoFactorUser('ian.b@justicequest.pro', orgAdmin: true);

        $response = $this->post('/login', [
            'email' => 'ian.b@justicequest.pro',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);

        $response->assertRedirect('/home');
        $response->assertSessionMissing('login.id');
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));
        $this->assertGuest();

        // Matching is case-insensitive on the submitted username.
        $this->post('/login', [
            'email' => 'Ian.B@JusticeQuest.Pro',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_non_exempt_confirmed_user_still_receives_challenge(): void
    {
        config(['fortify.two_factor_exempt_emails' => ['ian.b@justicequest.pro']]);

        $this->makeConfirmedTwoFactorUser('colleague@example.com');

        $this->post('/login', [
            'email' => 'colleague@example.com',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_exempt_email_with_wrong_password_is_rejected_as_usual(): void
    {
        config(['fortify.two_factor_exempt_emails' => ['ian.b@justicequest.pro']]);

        $this->makeConfirmedTwoFactorUser('ian.b@justicequest.pro', orgAdmin: true);

        $this->postJson('/login', [
            'email' => 'ian.b@justicequest.pro',
            'password' => 'wrong-password-1',
        ])->assertStatus(422)->assertJson(['code' => 'invalid_credentials']);

        $this->assertGuest();
    }

    public function test_empty_exempt_list_keeps_the_challenge_for_everyone(): void
    {
        config(['fortify.two_factor_exempt_emails' => []]);

        $this->makeConfirmedTwoFactorUser('ian.b@justicequest.pro', orgAdmin: true);

        $this->post('/login', [
            'email' => 'ian.b@justicequest.pro',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }
}
