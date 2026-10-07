<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Ticket 5 verdict tests — RBAC admin, teams, invitations, session
 * management. Backend only (001-D06: no Blade). Every test exercises HTTP
 * POSTs/JSON and asserts status codes, JSON bodies, database state, and
 * mail — never rendered HTML.
 *
 * The HIBP k-anonymity API is faked in setUp: tests never touch the network
 * (admin user creation reuses the shared password policy, C-02).
 */
class AdminRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->fakeHibp();
    }

    /**
     * Fake the HIBP k-anonymity API (same pattern as AuthFlowsTest).
     *
     * @param  list<string>  $breached  passwords to report as breached
     */
    private function fakeHibp(array $breached = [], bool $down = false): void
    {
        $stubs = [];

        foreach ($breached as $password) {
            $hash = strtoupper(sha1($password));
            $stubs['https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)] =
                Http::response(substr($hash, 5).":7\n", 200);
        }

        $stubs['https://api.pwnedpasswords.com/range/*'] = $down
            ? Http::response(null, 503)
            : Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:2\n", 200);

        Http::fake($stubs);
    }

    private function sessionCookieName(): string
    {
        return (string) config('session.cookie');
    }

    /**
     * Log in over HTTP and bind the session cookie so subsequent JSON
     * requests run as that session (the test client does not persist
     * cookies on its own).
     */
    private function loginAs(string $email, string $password = FixtureLoader::DEFAULT_PASSWORD): void
    {
        $response = $this->postJson('/login', ['email' => $email, 'password' => $password]);
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $this->sessionCookieName());

        $this->assertNotNull($cookie);
        $this->withCredentials()->withUnencryptedCookie($this->sessionCookieName(), $cookie->getValue());
    }

    /**
     * Drop the bound session cookie and start over with a fresh,
     * server-unknown session id (simulates a new device).
     *
     * Two in-test persistences must be cleared: the guard instance is
     * shared across requests (so resolved guards are forgotten), and the
     * session Store is shared too (its attributes accumulate via
     * array_replace, so the old login key would otherwise keep the "new
     * device" authenticated).
     *
     * The cookie value is encrypted exactly the way
     * prepareCookiesForRequest() does it, and written to the
     * unencrypted-cookies slot so it overrides the cookie bound by
     * loginAs().
     */
    private function newDevice(): void
    {
        $this->app['auth']->forgetGuards();
        session()->flush();

        $name = $this->sessionCookieName();
        $value = encrypt(
            CookieValuePrefix::create($name, (string) app('encrypter')->getKey()).Str::random(40),
            false
        );
        $this->withUnencryptedCookie($name, $value);
    }

    private function orgAdminRole(string $orgId): Role
    {
        return Role::where('org_id', $orgId)
            ->where('name', 'org_admin')
            ->where('guard_name', (string) config('auth.defaults.guard', 'web'))
            ->firstOrFail();
    }

    // ── C-05 ─────────────────────────────────────────────────────────────

    /**
     * C-05: the invitation token is bound to the invitee email — a different
     * signed-in user is rejected with the generic message (no enumeration).
     */
    public function test_invitation_token_rejected_for_different_signed_in_user(): void
    {
        $loader = FixtureLoader::load();
        $token = $loader->invitationToken();
        $this->assertNotNull($token);

        // Signed in as grace@sterling.test; the invitation is for
        // new@sterling.test.
        $this->actingAs($loader->user('user_attorney_granted'));

        $this->postJson("/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJson(['code' => 'invitation_invalid']);

        // The invitation is untouched: still pending, still usable by the
        // real invitee.
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->revoked_at);

        // The denial is audited against the invitation's org.
        $denied = AuditEvent::where('event', 'invitation.accept.denied')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame($loader->id('org_sterling'), (string) $denied->org_id);
        $this->assertSame('new@sterling.test', $denied->payload['email']);
    }

    /**
     * C-05: the happy path — an invited existing user accepts and the
     * matter-scoped grant is created; the invitation mail carries a usable
     * accept link and stores only the token hash.
     */
    public function test_invitation_accept_by_matching_user_creates_matter_grant(): void
    {
        Mail::fake();
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $matter = $loader->matter('matter_001');
        $invitee = $loader->user('user_paralegal');

        $this->actingAs($admin);

        $this->postJson('/admin/invitations', [
            'email' => $invitee->email,
            'role' => 'viewer',
            'matter_id' => $matter->getKey(),
        ])->assertCreated();

        $token = null;
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token): bool {
            $path = parse_url($mail->acceptUrl, PHP_URL_PATH);
            $token = is_string($path) ? basename($path) : null;

            return true;
        });
        $this->assertNotNull($token);

        // Only the hash is stored — the plaintext token is not in the DB.
        $invitation = Invitation::where('email', $invitee->email)->firstOrFail();
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        // The matching signed-in user accepts.
        $this->actingAs($invitee);
        $this->postJson("/invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.email', (string) $invitee->email);

        $this->assertNotNull($invitation->fresh()->accepted_at);

        // Matter-scoped grant created for the existing user.
        $this->assertDatabaseHas('matter_grants', [
            'matter_id' => $matter->getKey(),
            'user_id' => $invitee->getKey(),
            'role' => 'viewer',
        ]);
        $this->assertDatabaseHas('audit_events', ['event' => 'invitation.accepted']);
        $this->assertDatabaseHas('audit_events', ['event' => 'matter.grant.created']);

        // Single-use: a second accept is rejected generically.
        $this->postJson("/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJson(['code' => 'invitation_invalid']);
    }

    /**
     * C-05: invitation list shows pending invitations; revoke works and is
     * audited; revoked invitations cannot be accepted. Leak sentinel: the
     * token hash never appears in the JSON.
     */
    public function test_invitation_list_revoke_and_leak_sentinels(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $this->actingAs($admin);

        $list = $this->getJson('/admin/invitations')->assertOk();
        $this->assertStringNotContainsString('token_hash', $list->getContent());

        $invitation = Invitation::where('email', 'new@sterling.test')->firstOrFail();

        $this->deleteJson("/admin/invitations/{$invitation->getKey()}")->assertNoContent();
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'invitation.revoked']);

        // A revoked invitation cannot be accepted, even by the invitee.
        $token = $loader->invitationToken();
        $this->assertNotNull($token);
        $invitee = User::create([
            'org_id' => $loader->id('org_sterling'),
            'name' => 'New Sterling',
            'email' => 'new@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $invitee->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($invitee);
        $this->postJson("/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJson(['code' => 'invitation_invalid']);
    }

    /**
     * C-05: the nightly purge deletes expired, unaccepted invitations and
     * keeps accepted ones plus still-valid ones.
     */
    public function test_purge_expired_invitations_command(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $orgId = $loader->id('org_sterling');

        Invitation::create([
            'org_id' => $orgId,
            'email' => 'old@sterling.test',
            'token_hash' => hash('sha256', Str::random(64)),
            'role' => 'viewer',
            'invited_by' => $admin->getKey(),
            'expires_at' => now()->subDay(),
        ]);
        Invitation::create([
            'org_id' => $orgId,
            'email' => 'accepted@sterling.test',
            'token_hash' => hash('sha256', Str::random(64)),
            'role' => 'viewer',
            'invited_by' => $admin->getKey(),
            'expires_at' => now()->subDay(),
            'accepted_at' => now()->subDays(2),
        ]);

        $this->artisan('invitations:purge-expired')->assertSuccessful();

        $this->assertNull(Invitation::where('email', 'old@sterling.test')->first());
        $this->assertNotNull(Invitation::where('email', 'accepted@sterling.test')->first());
        // The adv fixture invitation (7-day TTL) survives.
        $this->assertNotNull(Invitation::where('email', 'new@sterling.test')->first());
    }

    // ── C-07 ─────────────────────────────────────────────────────────────

    /**
     * C-07: the user sees their sessions, can revoke one (behind
     * password.confirm), and the concurrent-session limit (5) kicks the
     * oldest when a new device logs in.
     */
    public function test_session_revoke_and_concurrent_session_limit(): void
    {
        $loader = FixtureLoader::load();
        $viewer = $loader->user('user_viewer');

        // — list + per-session revoke —
        $this->loginAs((string) $viewer->email);

        $list = $this->getJson('/sessions')->assertOk();
        $sessions = $list->json('data');
        $this->assertCount(1, $sessions);
        $this->assertTrue($sessions[0]['is_current']);

        // A second device: a manually-inserted row (same shape the DB
        // session driver writes).
        $otherId = (string) Str::uuid();
        DB::table('sessions')->insert([
            'id' => $otherId,
            'user_id' => $viewer->getKey(),
            'ip_address' => '10.0.0.2',
            'user_agent' => 'OtherDevice/1.0',
            'payload' => '',
            'last_activity' => time() - 60,
        ]);
        $this->assertCount(2, $this->getJson('/sessions')->json('data'));

        // Revoke sits behind password.confirm: 423 first …
        $this->deleteJson("/sessions/{$otherId}")->assertStatus(423);
        $this->assertNotNull(DB::table('sessions')->where('id', $otherId)->first());

        // … then confirm and revoke.
        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertCreated();
        $this->deleteJson("/sessions/{$otherId}")->assertNoContent();
        $this->assertNull(DB::table('sessions')->where('id', $otherId)->first());

        $revoked = AuditEvent::where('event', 'session.revoked')->latest('created_at')->firstOrFail();
        $this->assertSame(1, $revoked->payload['revoked_count']);

        // — concurrent-session limit: 5 pre-existing device sessions, then a
        // 6th login from a new device kicks the oldest —
        //
        // NOTE: last_activity stays inside the 120-minute session GC window
        // on purpose — StartSession runs GC on a 2/100 lottery per request,
        // and rows older than the lifetime would be garbage-collected
        // nondeterministically, flaking the count below.
        $manualIds = [];
        foreach (range(1, 5) as $i) {
            $id = (string) Str::uuid();
            $manualIds[$i] = $id;
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $viewer->getKey(),
                'ip_address' => "10.0.1.{$i}",
                'user_agent' => "Device/{$i}",
                'payload' => '',
                'last_activity' => time() - ($i * 120),
            ]);
        }
        // 6 rows now: the current session + 5 manual ones.

        $this->newDevice();
        $this->postJson('/login', [
            'email' => (string) $viewer->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertOk();

        $remaining = DB::table('sessions')
            ->where('user_id', $viewer->getKey())
            ->orderBy('last_activity')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // 4 pre-existing newest kept + the fresh login = 5 (the limit).
        $this->assertCount(5, $remaining);
        $this->assertNotContains($manualIds[5], $remaining);
        $this->assertNotContains($manualIds[4], $remaining);
        $this->assertContains($manualIds[1], $remaining);
    }

    /**
     * C-07: revoke-all kills every session (behind password.confirm) and
     * is audited.
     */
    public function test_session_revoke_all(): void
    {
        $loader = FixtureLoader::load();
        $viewer = $loader->user('user_viewer');

        $this->loginAs((string) $viewer->email);

        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $viewer->getKey(),
            'ip_address' => '10.0.0.9',
            'user_agent' => 'OtherDevice/9.0',
            'payload' => '',
            'last_activity' => time() - 60,
        ]);

        $this->deleteJson('/sessions')->assertStatus(423);

        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertCreated();
        $this->deleteJson('/sessions')->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $viewer->getKey())->count());

        $revokedAll = AuditEvent::where('event', 'session.revoked_all')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(2, $revokedAll->payload['revoked_count']);

        // The current session is gone too: the next request is a guest.
        $this->getJson('/sessions')->assertUnauthorized();
    }

    /**
     * C-07: privileged roles (org_admin, attorney) get a 12 h absolute
     * lifetime; other roles are unaffected.
     */
    public function test_privileged_session_expires_after_twelve_hours(): void
    {
        $loader = FixtureLoader::load();
        $attorney = $loader->user('user_attorney_granted');

        $this->loginAs((string) $attorney->email);
        $this->getJson('/sessions')->assertOk();

        $this->travelTo(now()->addHours(13));

        $this->getJson('/sessions')
            ->assertStatus(403)
            ->assertJson(['code' => 'session_expired']);

        $this->assertDatabaseHas('audit_events', ['event' => 'auth.logout']);
    }

    public function test_non_privileged_session_survives_twelve_hours(): void
    {
        $loader = FixtureLoader::load();
        $viewer = $loader->user('user_viewer');

        $this->loginAs((string) $viewer->email);

        $this->travelTo(now()->addHours(13));

        $this->getJson('/sessions')->assertOk();
    }

    // ── C-10 ─────────────────────────────────────────────────────────────

    /**
     * C-10 core: outside_counsel cannot enumerate org users — 403 with the
     * contract's code, audited as user.admin.denied.
     */
    public function test_outside_counsel_cannot_list_org_users(): void
    {
        $loader = FixtureLoader::load();
        $this->actingAs($loader->user('user_outside'));

        $this->getJson('/admin/users')
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);

        $denied = AuditEvent::where('event', 'user.admin.denied')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(
            (string) $loader->user('user_outside')->getKey(),
            (string) $denied->actor_id
        );
    }

    /**
     * C-10 core: the admin sees the org directory (system org structurally
     * excluded — every query is scoped to the admin's org), and non-admins
     * are denied on every admin route.
     */
    public function test_org_admin_lists_org_users_and_system_org_excluded(): void
    {
        $loader = FixtureLoader::load();
        Organization::system();

        $this->actingAs($loader->user('user_admin'));

        $data = $this->getJson('/admin/users')->assertOk()->json('data');

        // Six Sterling users; the rival-org user is excluded by org scoping.
        $this->assertCount(6, $data);
        $emails = array_column($data, 'email');
        $this->assertNotContains('rita@rival.test', $emails);
        $this->assertContains('admin@sterling.test', $emails);

        // Leak sentinel: password hashes never appear.
        $this->assertStringNotContainsString('argon2', (string) json_encode($data));

        // Non-admins are denied across the admin surface.
        $this->actingAs($loader->user('user_attorney_granted'));
        $this->getJson('/admin/teams')->assertForbidden();
        $this->getJson('/admin/invitations')->assertForbidden();
        $this->postJson('/admin/users', [])->assertForbidden();
    }

    /**
     * Admin role assignment + deactivation: role changes are audited,
     * deactivation revokes sessions and blocks login, self-targeting is
     * refused.
     */
    public function test_admin_role_assignment_and_deactivation(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $viewer = $loader->user('user_viewer');
        $this->actingAs($admin);

        // Role assignment.
        $this->patchJson("/admin/users/{$viewer->getKey()}", ['role' => 'paralegal'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['paralegal']);
        $this->assertTrue($viewer->fresh()->hasRole('paralegal'));
        $this->assertDatabaseHas('audit_events', ['event' => 'user.role.assigned']);

        // Cannot change your own role (self-lockout guard).
        $this->patchJson("/admin/users/{$admin->getKey()}", ['role' => 'viewer'])
            ->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasRole('org_admin'));

        // Deactivation revokes sessions and blocks login.
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $viewer->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 't',
            'payload' => '',
            'last_activity' => time(),
        ]);
        $this->deleteJson("/admin/users/{$viewer->getKey()}")->assertNoContent();
        $this->assertNotNull($viewer->fresh()->deactivated_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $viewer->getKey())->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'user.deactivated']);

        // Cannot deactivate your own account.
        $this->deleteJson("/admin/users/{$admin->getKey()}")->assertStatus(422);

        // A deactivated account cannot log in (generic error, no enumeration).
        // actingAs() persists on the guard for the whole test, so log out
        // explicitly to become a real guest again.
        $this->app['auth']->guard((string) config('auth.defaults.guard', 'web'))->logout();
        $this->newDevice();
        $this->postJson('/login', [
            'email' => (string) $viewer->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertStatus(422)->assertJson(['code' => 'invalid_credentials']);
    }

    /**
     * Admin direct user creation honors the shared password policy and is
     * audited; duplicate emails in the org are rejected.
     */
    public function test_admin_creates_user(): void
    {
        $loader = FixtureLoader::load();
        $this->actingAs($loader->user('user_admin'));

        $response = $this->postJson('/admin/users', [
            'name' => 'Nadia New',
            'email' => 'nadia@sterling.test',
            'password' => 'Correct-Horse-99-Battery',
            'role' => 'attorney',
        ])->assertCreated();

        $user = User::where('email', 'nadia@sterling.test')->firstOrFail();
        $this->assertTrue($user->hasRole('attorney'));
        $this->assertNotNull($user->email_verified_at);
        $response->assertJsonPath('data.email', 'nadia@sterling.test');
        $this->assertDatabaseHas('audit_events', ['event' => 'user.created']);

        // Duplicate email in the org → 422.
        $this->postJson('/admin/users', [
            'name' => 'Nadia Again',
            'email' => 'nadia@sterling.test',
            'password' => 'Correct-Horse-99-Battery',
            'role' => 'viewer',
        ])->assertStatus(422);

        // Weak password → 422 (shared C-02 policy).
        $this->postJson('/admin/users', [
            'name' => 'Weak Wendy',
            'email' => 'wendy@sterling.test',
            'password' => 'short',
            'role' => 'viewer',
        ])->assertStatus(422);
    }

    // ── Teams ────────────────────────────────────────────────────────────

    /**
     * Team CRUD + membership; membership is computed at request time (a
     * change made outside the controller is visible immediately — no
     * caching of membership).
     */
    public function test_team_crud_and_membership(): void
    {
        $loader = FixtureLoader::load();
        $this->actingAs($loader->user('user_admin'));

        $teamId = $this->postJson('/admin/teams', ['name' => 'Trial Team'])
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/admin/teams')->assertOk()->assertJsonFragment(['name' => 'Trial Team']);

        $pete = $loader->user('user_paralegal');
        $vera = $loader->user('user_viewer');

        $this->patchJson("/admin/teams/{$teamId}", [
            'members' => [(string) $pete->getKey()],
        ])->assertOk()->assertJsonPath('data.members_count', 1);

        // Membership computed at request time: a change made behind the
        // controller's back is visible immediately.
        Team::findOrFail($teamId)->users()->attach($vera->getKey());
        $members = $this->getJson("/admin/teams/{$teamId}")->assertOk()->json('data.members');
        $this->assertCount(2, $members);

        // Cross-org user ids are rejected.
        $this->patchJson("/admin/teams/{$teamId}", [
            'members' => [$loader->user('user_rival')->getKey()],
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_events', ['event' => 'team.membership.updated']);

        $this->deleteJson("/admin/teams/{$teamId}")->assertNoContent();
        $this->assertNull(Team::find($teamId));
    }

    // ── 2FA required for org admins ──────────────────────────────────────

    /**
     * T-04 deferred this (no contract behavior specified): an org admin
     * without 2FA enrolled is denied at login completion with 403
     * {code: "mfa_required"} — never left authenticated.
     */
    public function test_org_admin_without_mfa_gets_mfa_required_on_login(): void
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $admin = User::create([
            'org_id' => $orgId,
            'name' => 'MFA-less Admin',
            'email' => 'mfaless@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole($this->orgAdminRole($orgId));

        $this->assertNull($admin->two_factor_secret);

        $this->postJson('/login', [
            'email' => 'mfaless@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertStatus(403)->assertJson(['code' => 'mfa_required']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login.failed']);
    }

    /**
     * The fixture org admin HAS 2FA enrolled: login proceeds to the 2FA
     * challenge rather than the mfa_required denial.
     */
    public function test_org_admin_with_mfa_is_challenged_not_denied(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $this->assertNotNull($admin->two_factor_secret);

        $this->postJson('/login', [
            'email' => (string) $admin->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertOk()->assertJson(['two_factor' => true]);
    }

    // ── Invitation landing ───────────────────────────────────────────────

    public function test_invitation_show_and_invalid_token(): void
    {
        $loader = FixtureLoader::load();
        $token = $loader->invitationToken();
        $this->assertNotNull($token);

        // 004-D01: this test asserts the JSON contract, so it declares
        // itself an API client explicitly — a headerless GET is now a web
        // request and receives the Blade view instead.
        $this->getJson("/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.email', 'new@sterling.test')
            ->assertJsonPath('data.role', 'viewer');

        $this->getJson('/invitations/'.Str::random(64))
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);
    }
}
