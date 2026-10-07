<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 004 T-02 — admin invitations UI.
 *
 * The invitations page (resources/views/admin/invitations/index.blade.php)
 * lists only the admin's own org invitations, shows the one-time accept link
 * after creation, and revokes pending invitations from the UI.
 */
class AdminInvitationsUiTest extends TestCase
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
     * C-01: the admin table lists only the admin's own org invitations — the
     * cross-org adversarial fixture (INV-06) never appears.
     */
    public function test_admin_invitations_index_lists_only_org_invitations(): void
    {
        $rivalOrg = $this->fixtures->org('org_rival');
        $rivalAdmin = User::factory()->create([
            'org_id' => $rivalOrg->getKey(),
            'email' => 'boss@rival.test',
        ]);
        $rivalAdmin->assignRole(
            Role::where('org_id', $rivalOrg->getKey())->where('name', 'org_admin')->firstOrFail()
        );

        // INV-06: a pending invitation living in the rival org.
        app(InvitationService::class)->inviteWithToken(
            $rivalOrg,
            'spy@rival.example',
            'attorney',
            null,
            $rivalAdmin,
        );

        $this->assertTrue(Invitation::where('email', 'spy@rival.example')->exists());

        $this->actingAs($this->fixtures->user('user_admin'));

        $html = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $html->assertViewIs('admin.invitations.index');
        $html->assertDontSee('spy@rival.example');
        // The admin's own org invitations do appear.
        $html->assertSee('new@sterling.test');
    }

    /**
     * C-02: creating an invitation renders the one-time accept link in the
     * banner; the link is shown once — a refresh no longer shows it.
     */
    public function test_admin_creates_invitation_and_receives_accept_link(): void
    {
        $this->actingAs($this->fixtures->user('user_admin'));

        $response = $this->post('/admin/invitations', [
            'email' => 'fresh-hire@sterling.test',
            'role' => 'paralegal',
        ], ['Accept' => 'text/html']);

        $response->assertRedirect(route('admin.invitations.index'));
        $url = session('invitation_accept_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/invitations/', $url);

        // The link renders in the one-time banner, alongside the new row…
        $this->get('/admin/invitations', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertSee('Invitation created')
            ->assertSee($url)
            ->assertSee('fresh-hire@sterling.test');

        // …and is gone on the next render (flash data, never persisted).
        $this->get('/admin/invitations', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertDontSee($url)
            ->assertSee('fresh-hire@sterling.test');
    }

    /**
     * C-03: revoking a pending invitation from the UI sets revoked_at and the
     * row renders the Revoked badge with no revoke action left.
     */
    public function test_admin_revokes_pending_invitation_from_ui(): void
    {
        $this->actingAs($this->fixtures->user('user_admin'));

        $invitation = Invitation::where('email', 'new@sterling.test')->firstOrFail();
        $this->assertNull($invitation->revoked_at);

        $this->from('/admin/invitations')
            ->delete('/admin/invitations/'.$invitation->getKey(), [], ['Accept' => 'text/html'])
            ->assertRedirect('/admin/invitations')
            ->assertSessionHas('toast');

        $this->assertNotNull($invitation->fresh()->revoked_at);

        $html = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $html->assertSee('new@sterling.test');
        $html->assertSee('Revoked');
        $html->assertDontSee('revoke-'.$invitation->getKey());
    }
}
