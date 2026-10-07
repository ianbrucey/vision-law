<?php

namespace Tests\Architecture;

use App\Mail\InvitationMail;
use App\Models\Role;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-14 door 4 (leak sentinel): for a denied actor, JSON responses across
 * the matter/admin/auth endpoints contain no privileged strings —
 * password hashes, TOTP secrets, recovery codes, invitation tokens,
 * other orgs' emails, other matters' titles (00-brief.md leak sentinels).
 *
 * Allowed actors are probed too: privileged fields must never be rendered
 * even for org_admins. There are no Blade views in this feature (001-D06),
 * so the scan covers JSON bodies and the CSV export.
 */
class LeakSentinelTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> label => sentinel string that must never render */
    private array $sentinels = [];

    private FixtureLoader $fixtures;

    private string $invitationToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = FixtureLoader::load();

        $rival = $this->fixtures->user('user_rival');
        $admin = $this->fixtures->user('user_admin')->fresh();

        $recoveryCodes = json_decode((string) decrypt($admin->two_factor_recovery_codes), true);

        $this->sentinels = [
            'password hash' => (string) $rival->password,
            'TOTP secret' => (string) decrypt($admin->two_factor_secret),
            'recovery code' => (string) $recoveryCodes[0],
            'rival org email' => (string) $rival->email,
            'rival org name' => (string) $rival->name,
            'rival matter title' => 'Rival matter',
            'other sterling matter title' => 'Confidential internal investigation',
        ];

        // A live invitation plaintext token, captured from the mailed URL.
        // Only its hash is stored; the plaintext must never render in JSON.
        Mail::fake();
        app(InvitationService::class)->invite(
            $this->fixtures->org('org_sterling'),
            'sentinel-invitee@sterling.test',
            'viewer',
            null,
            $admin,
        );
        $token = '';
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token): bool {
            $token = basename($mail->acceptUrl);

            return true;
        });
        $this->assertNotSame('', $token);
        $this->invitationToken = $token;
        $this->sentinels['invitation token'] = $token;
    }

    private function assertNoSentinels(string $content, string $context): void
    {
        foreach ($this->sentinels as $label => $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $content,
                "Leak: {$label} present in {$context}"
            );
        }
    }

    private function loginAs(string $email, string $password = FixtureLoader::DEFAULT_PASSWORD): void
    {
        $response = $this->postJson('/login', ['email' => $email, 'password' => $password]);
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === (string) config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), $cookie->getValue());
    }

    public function test_denied_attorney_sees_no_privileged_strings(): void
    {
        $this->loginAs('nina@sterling.test'); // attorney, non-admin, no grants

        $rivalMatter = (string) $this->fixtures->matter('matter_rival')->getKey();
        $restrictedMatter = (string) $this->fixtures->matter('matter_002')->getKey();

        $users = $this->getJson('/admin/users')->assertForbidden();
        $this->assertNoSentinels($users->getContent(), 'GET /admin/users as denied attorney');

        $denied = $this->getJson('/admin/audit-events')->assertForbidden();
        $this->assertNoSentinels($denied->getContent(), 'GET /admin/audit-events as denied attorney');

        $export = $this->getJson('/admin/audit-events/export')->assertForbidden();
        $this->assertNoSentinels($export->getContent(), 'GET /admin/audit-events/export as denied attorney');

        $crossOrg = $this->getJson("/matters/{$rivalMatter}")->assertNotFound();
        $this->assertNoSentinels($crossOrg->getContent(), 'GET cross-org matter as denied attorney');

        $noGrant = $this->getJson("/matters/{$restrictedMatter}")->assertNotFound();
        $this->assertNoSentinels($noGrant->getContent(), 'GET no-grant matter as denied attorney');

        // Allowed surface (own session list) must still not render secrets.
        $sessions = $this->getJson('/sessions')->assertOk();
        $this->assertNoSentinels($sessions->getContent(), 'GET /sessions as attorney');
    }

    public function test_denied_outside_counsel_sees_no_privileged_strings(): void
    {
        $this->loginAs('owen@outside.test'); // outside_counsel: viewer grant on matter_001 only

        $grantedMatter = (string) $this->fixtures->matter('matter_001')->getKey();

        $denied = $this->getJson('/admin/users')->assertForbidden();
        $this->assertNoSentinels($denied->getContent(), 'GET /admin/users as outside counsel');

        // Granted matter is visible — but nothing beyond it may leak.
        $matter = $this->getJson("/matters/{$grantedMatter}")->assertOk();
        $this->assertNoSentinels($matter->getContent(), 'GET granted matter as outside counsel');
        $this->assertStringContainsString('Sterling v. Apex Construction', $matter->getContent());
    }

    public function test_cross_org_actor_sees_no_privileged_strings(): void
    {
        $this->loginAs('rita@rival.test');

        $sterlingMatter = (string) $this->fixtures->matter('matter_001')->getKey();

        $denied = $this->getJson("/matters/{$sterlingMatter}")->assertNotFound();
        $this->assertNoSentinels($denied->getContent(), 'GET cross-org matter as rival user');
    }

    public function test_guest_surfaces_render_no_privileged_strings(): void
    {
        // 004-D07 holder exception: the accept page is served to the valid
        // token holder, so the plaintext token may appear there — but ONLY in
        // the hidden invitation_token field and the sign-in next-link. Every
        // other sentinel must still be absent.
        $landing = $this->get("/invitations/{$this->invitationToken}")->assertOk();
        $landingContent = $landing->getContent();

        $holderSentinels = $this->sentinels;
        unset($holderSentinels['invitation token']);
        foreach ($holderSentinels as $label => $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $landingContent,
                "Leak: {$label} present in GET /invitations/{token}"
            );
        }
        $this->assertStringContainsString(
            'name="invitation_token" value="'.$this->invitationToken.'"',
            $landingContent,
            'The holder exception covers the hidden accept-form field.'
        );
        // Exactly two occurrences: the hidden field and the sign-in next-link
        // (URL-encoded inside next=). Any further echo would be a leak.
        $this->assertSame(
            2,
            substr_count($landingContent, $this->invitationToken),
            'The token may appear only in the hidden field and the sign-in next-link.'
        );

        // Failed login: generic body, no hash, no enumeration.
        $failed = $this->postJson('/login', [
            'email' => 'rita@rival.test',
            'password' => 'Wrong-Password-000',
        ])->assertStatus(422);
        $this->assertNoSentinels($failed->getContent(), 'POST /login failed');
    }

    public function test_allowed_admin_surfaces_render_no_privileged_strings(): void
    {
        $admin = $this->fixtures->user('user_admin');
        $this->actingAs($admin);

        $users = $this->getJson('/admin/users')->assertOk();
        $this->assertNoSentinels($users->getContent(), 'GET /admin/users as admin');

        $events = $this->getJson('/admin/audit-events')->assertOk();
        $this->assertNoSentinels($events->getContent(), 'GET /admin/audit-events as admin');

        // The export is watermarked but must still carry no secrets.
        $this->getJson('/admin/audit-events/export')->assertStatus(423);
        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertCreated();

        $export = $this->get('/admin/audit-events/export')->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('# vision-law audit log export', $csv);
        $this->assertNoSentinels($csv, 'GET /admin/audit-events/export as admin');
    }

    /**
     * 004-D07 holder exception: the plaintext invitation token may appear in
     * exactly one place — the one-time accept-link banner shown to the admin
     * who created the invitation. It must never appear in the admin table,
     * in logs, in JSON, or on any later render of the page.
     */
    public function test_admin_invitation_token_shows_only_in_one_time_banner(): void
    {
        $admin = $this->fixtures->user('user_admin');
        $this->actingAs($admin);

        // Plain render: no token anywhere — the mailed token from setUp must
        // not leak into the page either.
        $plain = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $this->assertStringNotContainsString(
            $this->invitationToken,
            $plain->getContent(),
            'Mailed token leaked into the plain admin invitations page.'
        );

        // The creating admin holds the token once — it renders in the
        // accept-link banner on the very next page load.
        $this->post('/admin/invitations', [
            'email' => 'banner-holder@sterling.test',
            'role' => 'viewer',
        ], ['Accept' => 'text/html'])->assertRedirect(route('admin.invitations.index'));

        $url = session('invitation_accept_url');
        $this->assertIsString($url);
        $token = basename($url);
        $this->assertNotSame('', $token);

        $banner = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $this->assertStringContainsString($token, $banner->getContent());
        // The table itself never carries the token — the banner is the only
        // door for it (004-D07).
        $bannerContent = $banner->getContent();
        $bannerOnly = (string) preg_replace(
            '/<input[^>]*id="accept-link-input"[^>]*>/',
            '',
            $bannerContent
        );
        $this->assertStringNotContainsString($token, $bannerOnly);

        // One-time: after the flash ages out, the token is gone everywhere.
        $after = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk();
        $this->assertStringNotContainsString($token, $after->getContent());
        $this->assertStringNotContainsString(
            $this->invitationToken,
            $after->getContent(),
            'Mailed token leaked into the admin invitations page after refresh.'
        );
    }

    /**
     * C-07 (token_hash): the token hash must never render in any UI
     * surface — not in the admin table HTML, not in the JSON payloads, not
     * on the accept page (guest or signed-in holder), and not on the
     * generic not-found page. The plaintext token is covered separately by
     * the 004-D07 holder-exception tests above.
     */
    public function test_token_hash_never_leaks_in_ui(): void
    {
        $admin = $this->fixtures->user('user_admin');
        $this->actingAs($admin);

        $issued = app(InvitationService::class)->inviteWithToken(
            $admin->organization,
            'hash-sentinel@sterling.test',
            'viewer',
            null,
            $admin,
        );
        $hash = (string) $issued->invitation->token_hash;
        $this->assertNotSame('', $hash);

        // Admin table HTML and its JSON twin.
        $table = $this->get('/admin/invitations', ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringNotContainsString($hash, $table, 'token_hash in admin table HTML');
        $json = $this->getJson('/admin/invitations')->assertOk()->getContent();
        $this->assertStringNotContainsString($hash, $json, 'token_hash in admin JSON');

        // The accept page for the valid holder — guest first, then a
        // signed-in holder (the holder exception covers the plaintext
        // token, never the hash).
        $token = $issued->token;
        $guestPage = $this->get("/invitations/{$token}", ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringNotContainsString($hash, $guestPage, 'token_hash on guest accept page');

        $this->actingAs(User::where('email', 'nina@sterling.test')->firstOrFail());
        $authedPage = $this->get("/invitations/{$token}", ['Accept' => 'text/html'])->assertOk()->getContent();
        $this->assertStringNotContainsString($hash, $authedPage, 'token_hash on signed-in accept page');

        // The generic not-found page.
        $notFound = $this->get('/invitations/not-a-real-token!!!', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->getContent();
        $this->assertStringNotContainsString($hash, $notFound, 'token_hash on 404 page');
    }

    /**
     * C-07 (INV-06): invitee emails never render outside their org. A
     * pending invitation living in the rival org never appears on the
     * Sterling admin table, on the generic not-found page, or on a
     * Sterling invitee's own accept page.
     */
    public function test_invitee_emails_never_render_outside_their_org(): void
    {
        $rivalOrg = $this->fixtures->org('org_rival');
        $rivalAdmin = User::factory()->create([
            'org_id' => $rivalOrg->getKey(),
            'email' => 'cross-org-boss@rival.test',
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

        // Sterling's admin table: nothing from the rival org.
        $this->actingAs($this->fixtures->user('user_admin'));
        $table = $this->get('/admin/invitations', ['Accept' => 'text/html'])
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('spy@rival.example', $table);

        // The generic not-found page reveals nothing about it.
        $notFound = $this->get('/invitations/definitely-not-a-token', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->getContent();
        $this->assertStringNotContainsString('spy@rival.example', $notFound);

        // A Sterling invitee's own accept page (valid token) carries only
        // their own email — nothing from the rival org.
        $sterlingAdmin = $this->fixtures->user('user_admin')->fresh();
        $own = app(InvitationService::class)->inviteWithToken(
            $sterlingAdmin->organization,
            'own@sterling.test',
            'viewer',
            null,
            $sterlingAdmin,
        );
        $ownPage = $this->get('/invitations/'.$own->token, ['Accept' => 'text/html'])
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('spy@rival.example', $ownPage);
        $this->assertStringContainsString('own@sterling.test', $ownPage);
    }
}
