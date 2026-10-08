<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\FixtureLoader;
use Tests\Helpers\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Spec 008 T-04 verdict — C-07: passkey storage and rendering never
 * expose private or secret-adjacent material.
 *
 * The authenticator's private key never leaves the device by protocol;
 * this test pins the server side: only the (encrypted-at-rest) public
 * key is stored, and neither it nor the credential id / aaguid appears
 * in the raw column in usable form, in rendered pages, or in audit
 * payloads.
 */
class PasskeySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkey_storage_contains_no_private_material(): void
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $user = User::create([
            'org_id' => $orgId,
            'name' => 'Storage User',
            'email' => 'pk-storage@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(
            Role::where('org_id', $orgId)->where('name', 'attorney')->firstOrFail()
        );

        $authenticator = VirtualAuthenticator::make();
        $this->actingAs($user);
        $options = $this->postJson(route('passkeys.register.options'))->assertOk()->json();
        $this->postJson(
            route('passkeys.register'),
            $authenticator->attest($options) + ['alias' => 'Storage phone']
        )->assertOk();

        $credentialId = $authenticator->credentialId();

        // ── Storage ──────────────────────────────────────────────────
        $row = DB::table('webauthn_credentials')->where('id', $credentialId)->first();
        $this->assertNotNull($row);

        // The public key is stored encrypted: the raw column is not the
        // key, in any recognizable form.
        $credential = $user->webAuthnCredentials()->firstOrFail();
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $credential->public_key);
        $this->assertNotSame($credential->public_key, $row->public_key);
        $this->assertStringNotContainsString('BEGIN PUBLIC KEY', (string) $row->public_key);
        $this->assertStringNotContainsString('BEGIN', (string) $row->public_key);

        // Nothing in the entire row is private-key material.
        $rowJson = (string) json_encode($row);
        $this->assertStringNotContainsString('PRIVATE KEY', $rowJson);

        // ── Rendered pages ───────────────────────────────────────────
        // Full-HTML sentinels: key material and secret-adjacent values.
        $sentinels = [
            (string) $row->aaguid,               // authenticator GUID
            substr((string) $row->public_key, 0, 32), // raw stored key fragment
            'BEGIN PUBLIC KEY',
            'PRIVATE KEY',
        ];

        // Settings page (skip the once-display visit first). The
        // credential id may appear ONLY as the revoke form's action URL
        // segment (resource addressing — 03-contract.md §Leak sentinels,
        // as amended); strip form actions before scanning for it.
        $this->get(route('two-factor.settings'))->assertOk();
        $settings = $this->get(route('two-factor.settings'))->assertOk()->getContent();
        foreach ($sentinels as $sentinel) {
            if ($sentinel !== '') {
                $this->assertStringNotContainsString($sentinel, $settings);
            }
        }
        $settingsWithoutActions = (string) preg_replace('#action="[^"]*"#', 'action=""', $settings);
        $this->assertStringNotContainsString($credentialId, $settingsWithoutActions);
        // The label IS rendered — it is the only credential datum shown.
        $this->assertStringContainsString('Storage phone', $settings);

        // Challenge page for this user shows none of it either.
        $this->post(route('logout'));
        $this->post('/login', [
            'email' => $user->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));
        $challenge = $this->get(route('two-factor.login'))->assertOk()->getContent();
        foreach ($sentinels as $sentinel) {
            if ($sentinel !== '') {
                $this->assertStringNotContainsString($sentinel, $challenge);
            }
        }
        // No form on the challenge page addresses a credential, so the
        // id is scanned strictly here.
        $this->assertStringNotContainsString($credentialId, $challenge);

        // ── Audit payloads ───────────────────────────────────────────
        foreach (DB::table('audit_events')->where('actor_id', $user->id)->get() as $event) {
            $this->assertStringNotContainsString($credentialId, (string) $event->payload);
            $this->assertStringNotContainsString('PRIVATE KEY', (string) $event->payload);
        }
    }
}
