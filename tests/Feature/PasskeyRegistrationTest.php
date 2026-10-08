<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Tests\Helpers\FixtureLoader;
use Tests\Helpers\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Spec 008 T-02 verdicts — passkey registration + management.
 *
 * C-01: the setup-mode enrollment page offers passkey enrollment beside
 *       TOTP, and setup mode permits the passkey routes.
 * C-02: a signed-in user registers a passkey through the ceremony
 *       endpoints; the credential persists, labeled.
 * C-03: completing passkey enrollment in setup mode clears the flag in
 *       the same session, and a passkey-only user's recovery codes are
 *       issued at confirmation (008-D02).
 * C-06 (management half): revocation requires password confirmation,
 *       removes the credential, and cannot reach another user's passkey.
 * C-11 (registration/revoke half): mfa.passkey.registered,
 *       mfa.passkey.recovery_codes.issued, and mfa.passkey.revoked land
 *       in audit_events without credential material in payloads.
 *
 * Ceremonies run against the test RP ID/origin pinned in phpunit.xml via
 * Tests\Helpers\VirtualAuthenticator (008-D03) — hermetic, no device.
 * Fixture users per specs/008-passkey-authentication/04-fixtures.json.
 */
class PasskeyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $role = 'attorney'): User
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $user = User::create([
            'org_id' => $orgId,
            'name' => 'Passkey User',
            'email' => $email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(
            Role::where('org_id', $orgId)->where('name', $role)->firstOrFail()
        );

        return $user;
    }

    /**
     * Drive the full registration ceremony for the currently signed-in
     * user and return the authenticator used.
     */
    private function registerPasskey(VirtualAuthenticator $authenticator, string $label): VirtualAuthenticator
    {
        $options = $this->postJson(route('passkeys.register.options'))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('challenge', $options);
        $this->assertSame('localhost', $options['rp']['id']);

        $this->postJson(
            route('passkeys.register'),
            $authenticator->attest($options) + ['alias' => $label]
        )->assertOk()->assertJsonPath('redirect', route('two-factor.settings'));

        return $authenticator;
    }

    public function test_setup_mode_offers_passkey_and_totp_enrollment(): void
    {
        // U-PK-02: org admin, no factor — lands in setup mode.
        $this->makeUser('pk-setup@sterling.test', 'org_admin');

        $this->post('/login', [
            'email' => 'pk-setup@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));

        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        // Both enrollment paths are offered on the enrollment page.
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('Passkeys')
            ->assertSee('Add a passkey')
            ->assertSee('Two-factor authentication')
            ->assertSee('Start setup');

        // Setup mode still restricts everything else...
        $this->get('/admin/invitations')->assertRedirect(route('two-factor.settings'));

        // ...but the passkey ceremony endpoints are reachable inside it.
        $this->postJson(route('passkeys.register.options'))->assertOk();
    }

    public function test_user_registers_passkey_via_ui(): void
    {
        // U-PK-03: firm attorney, no factor.
        $user = $this->makeUser('pk-register@sterling.test');
        $this->actingAs($user);

        $authenticator = $this->registerPasskey(VirtualAuthenticator::make(), 'iPhone');

        $this->assertDatabaseCount('webauthn_credentials', 1);
        $this->assertDatabaseHas('webauthn_credentials', [
            'id' => $authenticator->credentialId(),
            'alias' => 'iPhone',
        ]);
        $this->assertTrue($user->fresh()->hasPasskeys());

        // The settings page lists the passkey by its label — on the visit
        // after the recovery-codes once-display (008-D02 + 005-D03: the
        // first visit shows the freshly issued codes, not the list).
        $this->get(route('two-factor.settings'))->assertOk();
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('iPhone')
            ->assertSee('Added ');
    }

    public function test_passkey_enrollment_clears_setup_mode(): void
    {
        // U-PK-01: org admin, no factor — setup mode until enrollment.
        $admin = $this->makeUser('pk-clear@sterling.test', 'org_admin');

        $this->post('/login', [
            'email' => 'pk-clear@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.settings'));
        $this->assertTrue((bool) session(RestrictToTwoFactorSetup::SESSION_KEY));

        $this->registerPasskey(VirtualAuthenticator::make(), 'Office key');

        // Same session, immediately unrestricted (005-D01 semantics).
        $this->assertEmpty(session(RestrictToTwoFactorSetup::SESSION_KEY));

        // 008-D02: recovery codes were issued at confirmation — stored in
        // Fortify's shape and shown exactly once on the enrollment page.
        // The once-display is consumed by the very next request, so it is
        // asserted before any other navigation.
        $codes = $admin->fresh()->recoveryCodes();
        $this->assertCount(8, $codes);

        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertSee('Save your recovery codes');
        $this->get(route('two-factor.settings'))
            ->assertOk()
            ->assertDontSee('Save your recovery codes');

        $this->get('/admin/invitations')->assertOk();
    }

    public function test_passkey_registration_does_not_reissue_existing_recovery_codes(): void
    {
        // A user who already holds codes (e.g. from TOTP) keeps the same
        // set when adding a passkey — issuance is first-factor only.
        $user = $this->makeUser('pk-keepcodes@sterling.test');

        $original = collect(range(1, 8))->map(fn () => RecoveryCode::generate())->all();
        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($original)),
        ])->save();

        $this->actingAs($user);
        $this->registerPasskey(VirtualAuthenticator::make(), 'iPhone');

        $this->assertSame($original, $user->fresh()->recoveryCodes());
        $this->assertDatabaseMissing('audit_events', [
            'event' => 'mfa.passkey.recovery_codes.issued',
        ]);
    }

    public function test_user_can_revoke_passkey(): void
    {
        $user = $this->makeUser('pk-revoke@sterling.test');
        $this->actingAs($user);
        $authenticator = $this->registerPasskey(VirtualAuthenticator::make(), 'Old phone');

        $credentialId = $authenticator->credentialId();

        // Without a confirmed password the DELETE is refused at the gate
        // and the passkey survives (mirrors the 2FA disable flow, C-06).
        $this->delete(route('passkeys.destroy', $credentialId))
            ->assertRedirect(route('password.confirm'));
        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credentialId]);

        // Confirm the password, then revoke.
        $this->post('/user/confirm-password', [
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect();

        $this->delete(route('passkeys.destroy', $credentialId))
            ->assertRedirect(route('two-factor.settings'));
        $this->assertDatabaseMissing('webauthn_credentials', ['id' => $credentialId]);
        $this->assertFalse($user->fresh()->hasPasskeys());

        // Another user's credential id is not reachable at all.
        $other = $this->makeUser('pk-other@sterling.test');
        $this->actingAs($other);
        $this->registerPasskey(VirtualAuthenticator::make(), 'Theirs');

        $this->actingAs($user);
        $this->post('/user/confirm-password', [
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $this->delete(route('passkeys.destroy', $other->webAuthnCredentials()->firstOrFail()->id))
            ->assertNotFound();
    }

    public function test_passkey_events_are_audited(): void
    {
        $user = $this->makeUser('pk-audit@sterling.test');
        $this->actingAs($user);
        $authenticator = $this->registerPasskey(VirtualAuthenticator::make(), 'Audited phone');
        $credentialId = $authenticator->credentialId();

        foreach (['mfa.passkey.registered', 'mfa.passkey.recovery_codes.issued'] as $event) {
            $this->assertDatabaseHas('audit_events', [
                'event' => $event,
                'actor_id' => $user->id,
            ]);
        }

        $this->post('/user/confirm-password', [
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $this->delete(route('passkeys.destroy', $credentialId));

        $this->assertDatabaseHas('audit_events', [
            'event' => 'mfa.passkey.revoked',
            'actor_id' => $user->id,
        ]);

        // No payload carries credential material — the id itself is
        // treated as secret-adjacent and never audited (03-contract.md).
        foreach (DB::table('audit_events')->where('actor_id', $user->id)->get() as $row) {
            $this->assertStringNotContainsString($credentialId, (string) $row->payload);
        }
    }

    public function test_guests_cannot_reach_passkey_routes(): void
    {
        $this->post(route('passkeys.register.options'))->assertRedirect('/login');
        $this->post(route('passkeys.register'))->assertRedirect('/login');
    }
}
