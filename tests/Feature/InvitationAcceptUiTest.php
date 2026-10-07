<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\MatterGrant;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 004 T-03 — invitation accept UI (C-05, C-06).
 *
 * The accept page (GET /invitations/{token}, guest) renders the invitation
 * summary and the "Create your account" form posting to register.store;
 * the web accept path (POST /invitations/{token}/accept, auth) stays the
 * signed-in user's route. Invalid/expired/revoked/accepted tokens all
 * render one identical generic page (no enumeration).
 */
class InvitationAcceptUiTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = FixtureLoader::load();

        Mail::fake();
        Notification::fake();
    }

    /**
     * C-05 (new user): the accept page shows org, role chip, matter scope and
     * expiry; the "Create your account" form posts to register.store with the
     * hidden invitation_token. Ends with accepted_at set, the invited role
     * assigned, and the matter grant created for a scoped invitation.
     */
    public function test_invitee_accepts_invitation_through_ui_new_user(): void
    {
        $admin = $this->fixtures->user('user_admin')->fresh();
        $org = $admin->organization;
        $matter = $this->fixtures->matter('matter_001');

        $issued = app(InvitationService::class)->inviteWithToken(
            $org,
            'new.invitee@sterling.test',
            'outside_counsel',
            $matter,
            $admin,
        );

        // The accept page carries everything the invitee needs — and the
        // hidden token plus the sign-in next-link for the holder.
        $acceptUrl = route('invitations.show', ['token' => $issued->token]);
        $page = $this->get('/invitations/'.$issued->token, ['Accept' => 'text/html'])
            ->assertOk()
            ->assertViewIs('invitations.show');
        $page->assertSee($org->name);
        $page->assertSee('outside_counsel');
        $page->assertSee((string) $matter->title);
        $page->assertSee('You will see only this matter');
        $page->assertSee('name="invitation_token"', false);
        $page->assertSee('value="'.$issued->token.'"', false);
        $page->assertSee(route('login', ['next' => $acceptUrl]), false);
        $page->assertSee('Locked to the invitation', false);

        // Through the UI: the form posts name + password + locked email +
        // the hidden token to register.store. No auto-login for the
        // unverified account (C-01) — the browser flow lands on /login.
        $this->post('/register', [
            'name' => 'New Invitee',
            'email' => 'new.invitee@sterling.test',
            'password' => 'Correct-Horse-99-Battery',
            'invitation_token' => $issued->token,
        ])->assertRedirect('/login');
        $this->assertGuest();

        // The invitation is consumed, the role assigned, the grant created.
        $this->assertNotNull($issued->invitation->fresh()->accepted_at);

        $user = User::where('email', 'new.invitee@sterling.test')->firstOrFail();
        $this->assertSame((string) $org->getKey(), (string) $user->org_id);
        $this->assertTrue($user->hasRole('outside_counsel'));

        $this->assertTrue(
            MatterGrant::where('user_id', $user->getKey())
                ->where('matter_id', $matter->getKey())
                ->exists(),
            'A matter-scoped invitation creates the matter grant on accept.'
        );
    }

    /**
     * C-05 (existing user): the signed-in invitee accepts through the web
     * path → redirect to / with the welcome toast and accepted_at set.
     */
    public function test_existing_user_accepts_invitation_through_ui(): void
    {
        $admin = $this->fixtures->user('user_admin')->fresh();
        $invitee = User::where('email', 'nina@sterling.test')->firstOrFail();

        $issued = app(InvitationService::class)->inviteWithToken(
            $admin->organization,
            'nina@sterling.test',
            'viewer',
            null,
            $admin,
        );

        $this->actingAs($invitee);
        $this->post('/invitations/'.$issued->token.'/accept', [], ['Accept' => 'text/html'])
            ->assertRedirect('/')
            ->assertSessionHas('toast');

        $this->assertNotNull($issued->invitation->fresh()->accepted_at);
    }

    /**
     * C-06: unknown, expired, revoked, and accepted tokens render one
     * byte-identical generic page — no distinguishing detail (no
     * enumeration).
     */
    public function test_invalid_token_shows_safe_not_found_page(): void
    {
        $admin = $this->fixtures->user('user_admin')->fresh();
        $org = $admin->organization;
        $invitee = User::where('email', 'nina@sterling.test')->firstOrFail();
        $service = app(InvitationService::class);

        $unknown = $this->get('/invitations/not-a-real-token!!!', ['Accept' => 'text/html'])
            ->assertNotFound();
        $unknownBody = $unknown->getContent();
        $this->assertNotFalse($unknownBody);

        $expired = $service->inviteWithToken($org, 'stale@sterling.test', 'viewer', null, $admin);
        $expired->invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $revoked = $service->inviteWithToken($org, 'temp@sterling.test', 'viewer', null, $admin);
        $service->revoke($revoked->invitation, $admin);

        $accepted = $service->inviteWithToken($org, 'nina@sterling.test', 'viewer', null, $admin);
        $service->accept($accepted->token, $invitee);

        $bodies = [$unknownBody];
        foreach ([$expired->token, $revoked->token, $accepted->token] as $token) {
            $response = $this->get('/invitations/'.$token, ['Accept' => 'text/html'])
                ->assertNotFound();
            $body = $response->getContent();
            $this->assertNotFalse($body);
            $bodies[] = $body;
        }

        $this->assertCount(1, array_unique($bodies), 'All four failure states render the identical page.');

        // Nothing about the invitations leaks onto the page.
        foreach (['stale@sterling.test', 'temp@sterling.test', 'nina@sterling.test'] as $email) {
            $this->assertStringNotContainsString($email, $unknownBody);
        }
        $this->assertStringNotContainsString($expired->token, (string) end($bodies));
    }
}
