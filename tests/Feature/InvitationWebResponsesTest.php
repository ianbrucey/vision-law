<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 004 T-01 — response layer: web requests (Accept: text/html) receive
 * Blade views / redirects; API clients (Accept: application/json) keep the
 * existing JSON shapes unchanged.
 */
class InvitationWebResponsesTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = FixtureLoader::load();

        // Invitation mail is queued; keep it out of the way.
        Mail::fake();
    }

    /**
     * 004-D01: Accept: text/html → Blade views; Accept: application/json →
     * the existing JSON shapes. Invalid tokens 404 identically in both
     * modes (no enumeration).
     */
    public function test_web_requests_receive_blade_views(): void
    {
        // Public landing first — the route is guest-only.
        $token = $this->fixtures->invitationToken();
        $this->assertNotNull($token);

        $html = $this->get('/invitations/'.$token, ['Accept' => 'text/html'])->assertOk();
        $html->assertViewIs('invitations.show');
        $html->assertViewHasAll(['email', 'role', 'organizationName', 'expiresAt']);

        $json = $this->getJson('/invitations/'.$token)->assertOk();
        $json->assertJsonStructure([
            'data' => ['email', 'role', 'organization' => ['id', 'name'], 'expires_at'],
        ]);

        // Invalid token → identical 404 in both modes (no enumeration).
        $this->get('/invitations/definitely-not-a-token', ['Accept' => 'text/html'])->assertNotFound();
        $this->getJson('/invitations/definitely-not-a-token')
            ->assertNotFound()
            ->assertJson(['code' => 'not_found']);

        // Admin index — authenticated.
        $this->actingAs($this->fixtures->user('user_admin'));

        $html = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $html->assertViewIs('admin.invitations.index');

        $json = $this->getJson('/admin/invitations')->assertOk();
        $json->assertJsonStructure([
            'data' => [['id', 'email', 'role', 'matter_id', 'status', 'expires_at', 'created_at']],
        ]);
    }

    /**
     * Web store → redirect to the index with the one-time accept link
     * flashed (shown once, never persisted); JSON store → 201, shape
     * unchanged.
     */
    public function test_admin_store_web_redirects_with_one_time_accept_link(): void
    {
        $this->actingAs($this->fixtures->user('user_admin'));

        $response = $this->post('/admin/invitations', [
            'email' => 'invite-me@sterling.test',
            'role' => 'viewer',
        ], ['Accept' => 'text/html']);

        $response->assertRedirect(route('admin.invitations.index'));
        $response->assertSessionHas('invitation_accept_url');

        $url = session('invitation_accept_url');
        $this->assertIsString($url);
        $token = basename($url);
        $this->assertNotSame('', $token);
        $this->assertTrue(
            Invitation::where('token_hash', hash('sha256', $token))
                ->where('email', 'invite-me@sterling.test')
                ->exists(),
            'The flashed link carries the real token for the new invitation.'
        );

        // Shown once: the landing renders the banner, the next request does
        // not (flash data is aged out — never persisted).
        $this->get('/admin/invitations', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertSee($url);
        $this->get('/admin/invitations', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertDontSee($url);

        // JSON contract unchanged.
        $this->postJson('/admin/invitations', [
            'email' => 'invite-json@sterling.test',
            'role' => 'viewer',
        ])->assertCreated()->assertJsonStructure([
            'data' => ['id', 'email', 'role', 'matter_id', 'status', 'expires_at', 'created_at'],
        ]);
    }

    /**
     * Web store with bad input → back with field errors (not the JSON 422).
     */
    public function test_admin_store_web_validation_returns_back_with_errors(): void
    {
        $this->actingAs($this->fixtures->user('user_admin'));

        $this->from('/admin/invitations')
            ->post('/admin/invitations', [
                'email' => 'not-an-email',
                'role' => 'viewer',
            ], ['Accept' => 'text/html'])
            ->assertRedirect('/admin/invitations')
            ->assertSessionHasErrors('email');
    }

    /**
     * Web revoke → redirect back with a toast; JSON revoke → 204 (existing
     * contract, covered in AdminRbacTest).
     */
    public function test_admin_destroy_web_redirects_with_toast(): void
    {
        $this->actingAs($this->fixtures->user('user_admin'));

        $invitation = Invitation::where('email', 'new@sterling.test')->firstOrFail();

        $this->from('/admin/invitations')
            ->delete('/admin/invitations/'.$invitation->getKey(), [], ['Accept' => 'text/html'])
            ->assertRedirect('/admin/invitations')
            ->assertSessionHas('toast');

        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    /**
     * Accept: JSON keeps the existing shape; web success → redirect to /
     * with a welcome toast.
     */
    public function test_public_accept_web_redirects(): void
    {
        $admin = $this->fixtures->user('user_admin');
        $invitee = User::where('email', 'nina@sterling.test')->firstOrFail();

        $issued = app(InvitationService::class)->inviteWithToken(
            $admin->organization,
            'nina@sterling.test',
            'viewer',
            null,
            $admin,
        );

        // JSON contract unchanged.
        $this->actingAs($invitee);
        $this->postJson('/invitations/'.$issued->token.'/accept')
            ->assertOk()
            ->assertJsonStructure(['data' => ['user_id', 'org_id', 'email']]);

        // Web accept on a fresh invitation → redirect / with welcome toast.
        $issued2 = app(InvitationService::class)->inviteWithToken(
            $admin->organization,
            'nina@sterling.test',
            'viewer',
            null,
            $admin,
        );

        $this->post('/invitations/'.$issued2->token.'/accept', [], ['Accept' => 'text/html'])
            ->assertRedirect('/')
            ->assertSessionHas('toast');
    }

    /**
     * Web accept with a mismatched signed-in user → back to the accept page
     * with the generic invitation_invalid banner (no enumeration); the JSON
     * 422 contract is unchanged.
     */
    public function test_public_accept_web_failure_renders_generic_banner(): void
    {
        $admin = $this->fixtures->user('user_admin');
        $rival = $this->fixtures->user('user_rival');

        $issued = app(InvitationService::class)->inviteWithToken(
            $admin->organization,
            'nina@sterling.test',
            'viewer',
            null,
            $admin,
        );

        $this->actingAs($rival);
        $this->post('/invitations/'.$issued->token.'/accept', [], ['Accept' => 'text/html'])
            ->assertRedirect(route('invitations.show', ['token' => $issued->token]))
            ->assertSessionHas('invitation_error', 'invitation_invalid');

        // The invitation is untouched and still usable by the invitee.
        $this->assertNull($issued->invitation->fresh()->accepted_at);

        // JSON failure contract unchanged.
        $this->postJson('/invitations/'.$issued->token.'/accept')
            ->assertStatus(422)
            ->assertJson(['code' => 'invitation_invalid']);
    }
}
