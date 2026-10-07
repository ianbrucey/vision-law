<?php

namespace Tests\Architecture;

use App\Mail\InvitationMail;
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
        // The invitation landing is authorized by token possession — the
        // token itself must not be echoed back.
        $landing = $this->get("/invitations/{$this->invitationToken}")->assertOk();
        $this->assertNoSentinels($landing->getContent(), 'GET /invitations/{token}');

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
}
