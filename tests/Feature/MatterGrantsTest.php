<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\MatterGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Ticket 6 verdict tests — matter grants and the authorization proving
 * ground. Backend only (001-D06: no Blade). Every test exercises HTTP/JSON
 * and asserts status codes, JSON bodies, database state, and audit events —
 * never rendered HTML.
 *
 * Named verdicts: C-08 (test_cross_org_matter_access_returns_404),
 * C-09 (test_team_grant_gives_matter_access),
 * C-11 (test_expired_matter_grant_denies_access).
 *
 * Adversarial fixture cases (04-fixtures.json): adv_no_grant,
 * adv_expired_grant, adv_cross_org and adv_viewer_write are asserted here
 * with their exact HTTP code, audit event, and leak sentinels.
 * adv_wrong_user_invite, adv_outside_enumeration and adv_brute_force are
 * asserted with exact code + audit event in AdminRbacTest/AuthFlowsTest;
 * adv_weak_password's contract-mandated audit behavior (validation failures
 * are rejected pre-mutation and write NO audit row — 03-contract.md §Error
 * catalog) is pinned here because the C-02 test does not cover the audit
 * side.
 */
class MatterGrantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Fake the HIBP k-anonymity API (same pattern as AuthFlowsTest):
     * registration must never touch the network in tests.
     *
     * @param  list<string>  $breached  passwords to report as breached
     */
    private function fakeHibp(array $breached = []): void
    {
        $stubs = [];

        foreach ($breached as $password) {
            $hash = strtoupper(sha1($password));
            $stubs['https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)] =
                Http::response(substr($hash, 5).":7\n", 200);
        }

        $stubs['https://api.pwnedpasswords.com/range/*'] =
            Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:2\n", 200);

        Http::fake($stubs);
    }

    /**
     * Leak sentinels (00-brief.md): other matters' titles/numbers must never
     * appear in a response for a denied actor. Pass the matter keys the
     * actor is legitimately allowed to see (none, for denials).
     */
    private function assertNoMatterLeak(TestResponse $response, FixtureLoader $loader, string ...$visible): void
    {
        foreach (['matter_001', 'matter_002', 'matter_rival'] as $key) {
            if (in_array($key, $visible, true)) {
                continue;
            }

            $matter = $loader->matter($key);
            $response->assertDontSee((string) $matter->title);
            $response->assertDontSee((string) $matter->matter_number);
        }
    }

    private function latestDeniedAudit(string $actorId): AuditEvent
    {
        return AuditEvent::where('event', 'matter.access.denied')
            ->where('actor_id', $actorId)
            ->latest('created_at')
            ->firstOrFail();
    }

    /**
     * C-08 / adv_cross_org: a user from another org gets 404 (no existence
     * leak) and the denial is audited.
     */
    public function test_cross_org_matter_access_returns_404(): void
    {
        $loader = FixtureLoader::load();
        $rival = $loader->user('user_rival');
        $matter = $loader->matter('matter_001');

        $this->actingAs($rival);

        $response = $this->getJson("/matters/{$matter->getKey()}");
        $response->assertStatus(404)->assertJson(['code' => 'not_found']);

        $this->assertNoMatterLeak($response, $loader);

        $denied = $this->latestDeniedAudit((string) $rival->getKey());
        $this->assertSame((string) $matter->getKey(), $denied->payload['matter_id']);
        $this->assertSame('view', $denied->payload['attempted_action']);
    }

    /**
     * C-11 / adv_expired_grant: an expired grant denies automatically —
     * 404 (the actor must not learn the matter exists) + audit.
     */
    public function test_expired_matter_grant_denies_access(): void
    {
        $loader = FixtureLoader::load();
        $user = $loader->user('user_attorney_granted');
        // matter_002: the user's only grant there is expired (fixture).
        $matter = $loader->matter('matter_002');

        $this->actingAs($user);

        $response = $this->getJson("/matters/{$matter->getKey()}");
        $response->assertStatus(404)->assertJson(['code' => 'not_found']);

        $this->assertNoMatterLeak($response, $loader);

        $denied = $this->latestDeniedAudit((string) $user->getKey());
        $this->assertSame((string) $matter->getKey(), $denied->payload['matter_id']);
        $this->assertSame('view', $denied->payload['attempted_action']);
    }

    /**
     * C-09: a team grant gives matter access, and membership is computed at
     * request time — removing the user from the team revokes access
     * immediately (no caching).
     */
    public function test_team_grant_gives_matter_access(): void
    {
        $loader = FixtureLoader::load();
        $paralegal = $loader->user('user_paralegal');
        $matter = $loader->matter('matter_001');

        $this->actingAs($paralegal);

        // team_litigation holds a viewer grant on matter_001; the paralegal
        // is a member (fixture).
        $response = $this->getJson("/matters/{$matter->getKey()}");
        $response->assertOk()->assertJson([
            'matter_number' => 'MAT-2026-001',
            'title' => 'Sterling v. Apex Construction',
            'status' => 'open',
        ]);
        $this->assertNoMatterLeak($response, $loader, 'matter_001');

        // Membership change takes effect at request time.
        $loader->team('team_litigation')->users()->detach($paralegal->getKey());

        $denied = $this->getJson("/matters/{$matter->getKey()}");
        $denied->assertStatus(404)->assertJson(['code' => 'not_found']);
        $this->assertNoMatterLeak($denied, $loader);

        $this->latestDeniedAudit((string) $paralegal->getKey());
    }

    /**
     * adv_no_grant: same-org user with no grant on the matter → 404 + audit.
     */
    public function test_matter_access_without_grant_returns_404(): void
    {
        $loader = FixtureLoader::load();
        $user = $loader->user('user_attorney_nogrant');
        $matter = $loader->matter('matter_001');

        $this->actingAs($user);

        $response = $this->getJson("/matters/{$matter->getKey()}");
        $response->assertStatus(404)->assertJson(['code' => 'not_found']);

        $this->assertNoMatterLeak($response, $loader);

        $denied = $this->latestDeniedAudit((string) $user->getKey());
        $this->assertSame((string) $matter->getKey(), $denied->payload['matter_id']);
        $this->assertSame('view', $denied->payload['attempted_action']);
    }

    /**
     * adv_viewer_write: a viewer (matter visible) attempts a grant write →
     * 403 (not 404 — the object is visible, the role is insufficient) +
     * audit. No grant row may be created.
     */
    public function test_viewer_cannot_create_matter_grant(): void
    {
        $loader = FixtureLoader::load();
        $viewer = $loader->user('user_outside'); // explicit viewer grant on matter_001
        $matter = $loader->matter('matter_001');
        $target = $loader->user('user_attorney_nogrant');

        $this->actingAs($viewer);

        // Sanity: the viewer CAN see the matter.
        $this->getJson("/matters/{$matter->getKey()}")->assertOk();

        $response = $this->postJson("/matters/{$matter->getKey()}/grants", [
            'subject_type' => 'user',
            'subject_id' => $target->getKey(),
            'role' => 'viewer',
        ]);
        $response->assertStatus(403)->assertJson(['code' => 'forbidden']);

        $denied = $this->latestDeniedAudit((string) $viewer->getKey());
        $this->assertSame('grant', $denied->payload['attempted_action']);

        $this->assertDatabaseMissing('matter_grants', [
            'matter_id' => $matter->getKey(),
            'user_id' => $target->getKey(),
        ]);
    }

    /**
     * 403-not-404: an editor sees the matter but may not manage grants
     * (grant requires matter_admin+).
     */
    public function test_editor_cannot_create_matter_grant(): void
    {
        $loader = FixtureLoader::load();
        $editor = $loader->user('user_attorney_granted'); // editor on matter_001
        $matter = $loader->matter('matter_001');
        $target = $loader->user('user_attorney_nogrant');

        $this->actingAs($editor);

        $this->getJson("/matters/{$matter->getKey()}")->assertOk();

        $this->postJson("/matters/{$matter->getKey()}/grants", [
            'subject_type' => 'user',
            'subject_id' => $target->getKey(),
            'role' => 'viewer',
        ])->assertStatus(403)->assertJson(['code' => 'forbidden']);

        $denied = $this->latestDeniedAudit((string) $editor->getKey());
        $this->assertSame('grant', $denied->payload['attempted_action']);
    }

    /**
     * org_admin has implicit matter_owner on all org matters — no explicit
     * grant row needed for view or grant-manage.
     */
    public function test_org_admin_has_implicit_owner_on_all_org_matters(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $matter = $loader->matter('matter_002'); // no fixture grant for admin here

        $this->actingAs($admin);

        $this->getJson("/matters/{$matter->getKey()}")->assertOk();

        $this->assertTrue(Gate::forUser($admin)->allows('grant', $matter));
    }

    /**
     * outside_counsel is default-deny: no org-level matter access. An
     * explicit grant still works.
     */
    public function test_outside_counsel_is_default_deny_without_explicit_grant(): void
    {
        $loader = FixtureLoader::load();
        $outside = $loader->user('user_outside');

        $this->actingAs($outside);

        // matter_002: no grant → 404.
        $restricted = $loader->matter('matter_002');
        $response = $this->getJson("/matters/{$restricted->getKey()}");
        $response->assertStatus(404)->assertJson(['code' => 'not_found']);
        $this->assertNoMatterLeak($response, $loader);
        $this->latestDeniedAudit((string) $outside->getKey());

        // matter_001: explicit viewer grant (fixture) → visible.
        $granted = $loader->matter('matter_001');
        $this->getJson("/matters/{$granted->getKey()}")->assertOk();
    }

    /**
     * Deactivated users deny everything — even with an active grant.
     */
    public function test_deactivated_user_is_denied_everything(): void
    {
        $loader = FixtureLoader::load();
        $user = $loader->user('user_attorney_granted'); // active editor grant on matter_001
        $matter = $loader->matter('matter_001');

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($user);

        $response = $this->getJson("/matters/{$matter->getKey()}");
        $response->assertStatus(404)->assertJson(['code' => 'not_found']);
        $this->assertNoMatterLeak($response, $loader);
        $this->latestDeniedAudit((string) $user->getKey());
    }

    /**
     * Grant lifecycle: create (201 + matter.grant.created) → the subject can
     * view → revoke (200 + matter.grant.revoked) sets expires_at and NEVER
     * deletes the row → the subject is denied again.
     */
    public function test_grant_create_and_revoke_lifecycle(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $subject = $loader->user('user_attorney_nogrant');
        $matter = $loader->matter('matter_001');

        $this->actingAs($admin);

        $created = $this->postJson("/matters/{$matter->getKey()}/grants", [
            'subject_type' => 'user',
            'subject_id' => $subject->getKey(),
            'role' => 'viewer',
        ]);
        $created->assertStatus(201)->assertJson([
            'matter_id' => (string) $matter->getKey(),
            'subject' => ['type' => 'user', 'id' => (string) $subject->getKey()],
            'role' => 'viewer',
        ]);
        $grantId = $created->json('id');
        $this->assertNotEmpty($grantId);

        $grantCreated = AuditEvent::where('event', 'matter.grant.created')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame((string) $admin->getKey(), $grantCreated->payload['actor_id']);
        $this->assertSame((string) $matter->getKey(), $grantCreated->payload['matter_id']);
        $this->assertSame('viewer', $grantCreated->payload['role']);

        // The subject can now view the matter.
        $this->actingAs($subject);
        $this->getJson("/matters/{$matter->getKey()}")->assertOk();

        // Revoke: the row is expired, never deleted.
        $this->actingAs($admin);
        $this->deleteJson("/matters/{$matter->getKey()}/grants/{$grantId}")->assertOk();

        $grant = MatterGrant::findOrFail($grantId);
        $this->assertNotNull($grant->expires_at);

        $revoked = AuditEvent::where('event', 'matter.grant.revoked')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame((string) $matter->getKey(), $revoked->payload['matter_id']);

        // The subject is denied again.
        $this->actingAs($subject);
        $this->getJson("/matters/{$matter->getKey()}")
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);
    }

    /**
     * A matter must always retain at least one active owner-level grant:
     * removing the last one is rejected 422 pre-mutation (no audit row, no
     * state change). Removing one of two owners succeeds.
     */
    public function test_cannot_remove_last_owner_grant(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $matter = $loader->matter('matter_001');

        // The fixture's only owner grant on matter_001 belongs to the admin.
        $ownerGrant = MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->where('role', 'matter_owner')
            ->firstOrFail();
        $ownerGrantId = (string) $ownerGrant->getKey();

        $this->actingAs($admin);

        $this->deleteJson("/matters/{$matter->getKey()}/grants/{$ownerGrantId}")
            ->assertStatus(422)
            ->assertJson(['code' => 'cannot_remove_last_owner']);

        // Untouched: still active, no revocation audited.
        $this->assertNull(MatterGrant::findOrFail($ownerGrantId)->expires_at);
        $this->assertSame(
            0,
            AuditEvent::where('event', 'matter.grant.revoked')->count()
        );

        // With a second owner in place, removing one succeeds. user_paralegal
        // holds no direct grant on the matter (only team-based access), so
        // this creates a genuinely new owner row.
        $second = $this->postJson("/matters/{$matter->getKey()}/grants", [
            'subject_type' => 'user',
            'subject_id' => $loader->user('user_paralegal')->getKey(),
            'role' => 'matter_owner',
        ]);
        $second->assertStatus(201);

        $this->deleteJson("/matters/{$matter->getKey()}/grants/{$ownerGrantId}")->assertOk();
        $this->assertNotNull(MatterGrant::findOrFail($ownerGrantId)->expires_at);
    }

    /**
     * Grant subjects must belong to the matter's org — cross-org subjects
     * are a 422 validation failure (no audit row: rejected pre-mutation).
     */
    public function test_grant_create_rejects_subject_from_another_org(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $matter = $loader->matter('matter_001');
        $rivalUser = $loader->user('user_rival');

        $this->actingAs($admin);

        $this->postJson("/matters/{$matter->getKey()}/grants", [
            'subject_type' => 'user',
            'subject_id' => $rivalUser->getKey(),
            'role' => 'viewer',
        ])->assertStatus(422)->assertJson(['code' => 'validation']);

        $this->assertDatabaseMissing('matter_grants', [
            'matter_id' => $matter->getKey(),
            'user_id' => $rivalUser->getKey(),
        ]);
        $this->assertSame(0, AuditEvent::where('event', 'matter.grant.created')->count());
    }

    /**
     * Grant creation is idempotent: replaying the same grant returns the
     * existing row (200) instead of a duplicate.
     */
    public function test_grant_create_is_idempotent(): void
    {
        $loader = FixtureLoader::load();
        $admin = $loader->user('user_admin');
        $subject = $loader->user('user_attorney_nogrant');
        $matter = $loader->matter('matter_001');

        $this->actingAs($admin);

        $payload = [
            'subject_type' => 'user',
            'subject_id' => $subject->getKey(),
            'role' => 'viewer',
        ];

        $first = $this->postJson("/matters/{$matter->getKey()}/grants", $payload);
        $first->assertStatus(201);

        $second = $this->postJson("/matters/{$matter->getKey()}/grants", $payload);
        $second->assertStatus(200)->assertJson(['id' => $first->json('id')]);

        $this->assertSame(1, MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->where('user_id', $subject->getKey())
            ->count());
    }

    /**
     * The MatterPolicy delegates every decision to AccessControl — HTTP
     * middleware and Gate checks can never disagree.
     */
    public function test_matter_policy_delegates_to_access_control(): void
    {
        $loader = FixtureLoader::load();
        $matter = $loader->matter('matter_001');

        // editor: view yes, grant-manage no.
        $this->assertTrue(Gate::forUser($loader->user('user_attorney_granted'))->allows('view', $matter));
        $this->assertFalse(Gate::forUser($loader->user('user_attorney_granted'))->allows('grant', $matter));

        // No grant: nothing allowed.
        $this->assertFalse(Gate::forUser($loader->user('user_attorney_nogrant'))->allows('view', $matter));

        // Cross-org: nothing allowed.
        $this->assertFalse(Gate::forUser($loader->user('user_rival'))->allows('view', $matter));

        // org_admin: implicit matter_owner.
        $this->assertTrue(Gate::forUser($loader->user('user_admin'))->allows('grant', $matter));
        $this->assertTrue(Gate::forUser($loader->user('user_admin'))->allows('admin', $matter));
    }

    /**
     * adv_weak_password (audit side): weak and breached passwords are
     * rejected 422 AND — per the contract's error catalog ("rejected
     * pre-mutation") — write no audit row at all.
     */
    public function test_weak_password_rejection_writes_no_audit_event(): void
    {
        $org = FixtureLoader::load()->org('org_sterling');
        $org->update(['settings' => ['registration_mode' => 'open']]);

        $breachedPassword = 'Breach-Me-Please-99';
        $this->fakeHibp([$breachedPassword]);

        $this->postJson('/register', [
            'name' => 'Wally Weak',
            'email' => 'wally@example.test',
            'password' => 'password123',
        ])->assertStatus(422)->assertJson(['code' => 'validation']);

        $this->postJson('/register', [
            'name' => 'Betty Breached',
            'email' => 'betty@example.test',
            'password' => $breachedPassword,
        ])->assertStatus(422)->assertJson(['code' => 'validation']);

        $this->assertSame(0, AuditEvent::count());
        $this->assertDatabaseMissing('users', ['email' => 'wally@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'betty@example.test']);
    }
}
