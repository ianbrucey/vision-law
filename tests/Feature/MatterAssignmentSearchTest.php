<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\MatterGrant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 006 T-04 verdict tests — assignment, search, status summary.
 * Backend only (JSON).
 *
 * Named verdicts: C-08 (test_assignment_rules), C-09
 * (test_outside_counsel_scoping), C-10 (test_matter_search), C-11
 * (test_status_summary). The 10k-row search perf assertion is marked slow
 * (not skipped).
 */
class MatterAssignmentSearchTest extends TestCase
{
    use RefreshDatabase;

    // ── C-08 ──────────────────────────────────────────────────────────────

    public function test_assignment_rules(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $attorney = $loader->user('user-attorney');
        $paralegal = $loader->user('user-paralegal');
        $viewer = $loader->user('user-viewer');
        $matter = $loader->matter('matter-1');
        $mid = (string) $matter->getKey();

        // "My Matters": the unassigned viewer sees nothing, and unassigned
        // access 404s without leaking the title.
        $this->actingAs($viewer);
        $this->assertSame([], $this->getJson('/matters')->assertOk()->json('data'));
        $this->getJson("/matters/{$mid}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertDontSee('Sterling v. Apex Construction');

        $this->actingAs($admin);

        // Owner assigns the viewer.
        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertCreated()->assertJsonPath('role', 'viewer');

        // "My Matters" now includes the granted matter only.
        $this->actingAs($viewer);
        $list = $this->getJson('/matters')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($matter->matter_number, $list[0]['matter_number']);

        $this->actingAs($admin);

        // Demoting the FINAL owner → 422 last_owner.
        $attorneyGrant = MatterGrant::where('matter_id', $mid)
            ->where('user_id', $attorney->getKey())
            ->firstOrFail();

        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $attorney->getKey(),
            'role' => 'editor',
        ])->assertStatus(422)->assertJsonPath('code', 'last_owner');

        // Removing the final owner → 422 last_owner.
        $this->deleteJson("/matters/{$mid}/assignments/{$attorneyGrant->getKey()}")
            ->assertStatus(422)->assertJsonPath('code', 'last_owner');

        $this->assertTrue(AuditEvent::where('event', 'matter.assign.denied')
            ->where('matter_id', $mid)->exists());

        // With a second owner in place, demoting the first succeeds.
        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $admin->getKey(),
            'role' => 'owner',
        ])->assertCreated();

        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $attorney->getKey(),
            'role' => 'editor',
        ])->assertCreated()->assertJsonPath('role', 'editor');

        // A non-owner editor cannot assign — 403 at the :grant middleware.
        $this->actingAs($paralegal);
        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertForbidden()->assertJsonPath('code', 'forbidden');

        // A matter_admin (not owner) reaches the service and is denied
        // there — 403 with the matter.assign.denied audit event.
        MatterGrant::where('matter_id', $mid)
            ->where('user_id', $paralegal->getKey())
            ->update(['role' => 'matter_admin']);

        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertTrue(AuditEvent::where('event', 'matter.assign.denied')
            ->where('actor_id', (string) $paralegal->getKey())->exists());
    }

    // ── C-09 ──────────────────────────────────────────────────────────────

    public function test_outside_counsel_scoping(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $oc = $loader->user('user-oc');
        $m1 = $loader->matter('matter-1');
        $m2 = $loader->matter('matter-2');

        $this->actingAs($oc);

        // Granted matter: visible.
        $this->getJson('/matters/'.(string) $m1->getKey())->assertOk();

        // Ungranted matter: 404, title never leaks (leak sentinel).
        $this->getJson('/matters/'.(string) $m2->getKey())
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertDontSee('Harborview Lease Dispute');

        // The index ("My Matters") contains only the granted matter.
        $numbers = collect($this->getJson('/matters')->assertOk()->json('data'))
            ->pluck('matter_number')
            ->all();
        $this->assertContains($m1->matter_number, $numbers);
        $this->assertNotContains($m2->matter_number, $numbers);

        // No admin screens, no org directory.
        $this->getJson('/admin/users')->assertForbidden();

        // Outside-counsel actions are audit-marked external.
        $this->postJson('/matters/'.(string) $m1->getKey().'/comments', [
            'body' => 'Outside counsel review note.',
        ])->assertCreated();

        $added = AuditEvent::where('event', 'matter.comment.added')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertTrue($added->payload['external'] ?? false);
    }

    // ── C-10 ──────────────────────────────────────────────────────────────

    public function test_matter_search(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $viewer = $loader->user('user-viewer');
        $paralegal = $loader->user('user-paralegal');
        $m1 = $loader->matter('matter-1');

        $this->actingAs($admin);

        // A matter whose TITLE has no "Apex", but a PARTY does.
        $ridgewayId = $this->postJson('/matters', [
            'title' => 'Ridgeway Supply Dispute',
            'matter_type' => 'litigation',
            'client_name' => 'Ridgeway Corp',
        ])->assertCreated()->json('id');
        $ridgewayNumber = $this->getJson("/matters/{$ridgewayId}")->json('matter_number');

        $this->postJson("/matters/{$ridgewayId}/parties", [
            'party_type' => 'opposing_party',
            'name' => 'Apex Logistics LLC',
        ])->assertCreated();

        // "Apex" matches the title hit AND the party-name hit.
        $numbers = $this->pluckNumbers($this->getJson('/search/matters?q=Apex')->assertOk());
        $this->assertContains($m1->matter_number, $numbers);
        $this->assertContains($ridgewayNumber, $numbers);

        // Structured filters AND-combine: ACTIVE + litigation keeps m1
        // (ACTIVE/litigation) but drops Ridgeway (INTAKE/litigation).
        $filtered = $this->pluckNumbers(
            $this->getJson('/search/matters?state=ACTIVE&type=litigation')->assertOk()
        );
        $this->assertContains($m1->matter_number, $filtered);
        $this->assertNotContains($ridgewayNumber, $filtered);

        $this->assertSame(0, $this->getJson('/search/matters?state=ACTIVE&type=real_estate')
            ->assertOk()->json('meta.total'));

        // Assignee filter: matters where the paralegal holds a grant.
        $assigned = $this->pluckNumbers(
            $this->getJson('/search/matters?assignee='.(string) $paralegal->getKey())->assertOk()
        );
        $this->assertContains($m1->matter_number, $assigned);

        // Updated-since filter.
        $this->assertSame(0, $this->getJson('/search/matters?updated_since=2999-01-01')
            ->assertOk()->json('meta.total'));

        // Permission scoping BEFORE pagination: the viewer (granted on m1
        // only) never sees ungranted matters — no titles, no metadata.
        $this->postJson('/matters/'.(string) $m1->getKey().'/assignments', [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertCreated();

        $this->actingAs($viewer);
        $scoped = $this->getJson('/search/matters?q=Apex')->assertOk();
        $scopedNumbers = $this->pluckNumbers($scoped);
        $this->assertContains($m1->matter_number, $scopedNumbers);
        $this->assertNotContains($ridgewayNumber, $scopedNumbers);
        $scoped->assertDontSee('Ridgeway Supply Dispute');
        $scoped->assertDontSee('Harborview Lease Dispute');
        $scoped->assertDontSee('Meridian Internal Review');
    }

    /**
     * @param  TestResponse  $response
     * @return list<string>
     */
    private function pluckNumbers($response): array
    {
        return collect($response->json('data'))->pluck('matter_number')->all();
    }

    // ── C-11 ──────────────────────────────────────────────────────────────

    public function test_status_summary(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $paralegal = $loader->user('user-paralegal');

        $this->actingAs($attorney);

        // Built entirely through the API so every mutation writes its audit
        // row (fixture-loaded rows bypass the service and carry no audit
        // history — last_activity would be null for those).
        $mid = $this->postJson('/matters', [
            'title' => 'Summary test matter',
            'matter_type' => 'litigation',
            'client_name' => 'Summary Client Co.',
        ])->assertCreated()->json('id');

        $this->postJson("/matters/{$mid}/document-log", [
            'direction' => 'received',
            'counterparty' => 'Apex Inc',
            'logged_at' => '2026-10-01',
            'method' => 'email',
        ])->assertCreated();

        $this->postJson("/matters/{$mid}/document-log", [
            'direction' => 'sent',
            'counterparty' => 'Court Clerk',
            'logged_at' => '2026-10-02',
            'method' => 'upload',
        ])->assertCreated();

        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $paralegal->getKey(),
            'role' => 'editor',
        ])->assertCreated();

        // Contract shape, derived on read.
        $summary = $this->getJson("/matters/{$mid}/summary")->assertOk();
        $summary->assertJsonPath('lifecycle_state', 'INTAKE')
            ->assertJsonPath('document_log.received', 1)
            ->assertJsonPath('document_log.sent', 1)
            ->assertJsonPath('tasks.open', 0)
            ->assertJsonPath('tasks.overdue', 0)
            ->assertJsonPath('deadlines_next_14d', [])
            ->assertJsonPath('active_holds', []);
        $this->assertNotNull($summary->json('last_activity.at'));
        $this->assertSame('matter.assigned', $summary->json('last_activity.event'));

        $teamRoles = collect($summary->json('assigned_team'))
            ->pluck('role', 'user.email')
            ->all();
        $this->assertSame('matter_owner', $teamRoles['a.attorney@sterling.example']);
        $this->assertSame('editor', $teamRoles['p.paralegal@sterling.example']);

        // Unread comments recompute — no stale cache.
        $this->postJson("/matters/{$mid}/timeline/read")->assertOk();
        $this->assertSame(0, $this->getJson("/matters/{$mid}/summary")->json('unread_comments'));

        $this->actingAs($paralegal);
        $this->postJson("/matters/{$mid}/comments", ['body' => 'Fresh comment'])->assertCreated();

        $this->actingAs($attorney);
        $this->assertSame(1, $this->getJson("/matters/{$mid}/summary")->json('unread_comments'));

        // days_in_state tracks the last transition, not a cached value.
        $freshId = $this->postJson('/matters', [
            'title' => 'Days-in-state matter',
            'matter_type' => 'litigation',
            'client_name' => 'Days Client',
        ])->assertCreated()->json('id');

        $this->postJson("/matters/{$freshId}/parties", [
            'party_type' => 'client',
            'name' => 'Days Client',
        ])->assertCreated();

        // Travel BACKWARD: forward travel trips the absolute session
        // lifetime for privileged roles (EnsureSessionLifetime, C-07).
        // audit_events is append-only (001-D11), so backdating the
        // matter.created row is not an option either.
        // diffInDays counts completed 24h periods, so travel a margin
        // past 3 days to land on exactly 3.
        Carbon::setTestNow(now()->subDays(3)->subHour());
        $this->assertSame(3, $this->getJson("/matters/{$freshId}/summary")->json('days_in_state'));

        // Reset before transitioning: under backward travel the transition
        // audit row would carry an older created_at than matter.created,
        // and the "latest event" lookup orders by created_at.
        Carbon::setTestNow();
        $this->postJson("/matters/{$freshId}/transition", ['to' => 'ACTIVE'])->assertOk();
        $this->assertSame(0, $this->getJson("/matters/{$freshId}/summary")->json('days_in_state'));
    }

    // ── 10k-row perf assertion (slow, not skipped) ─────────────────────────

    #[Group('slow')]
    public function test_matter_search_performance(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $orgId = (string) $loader->org('org-sterling')->getKey();

        $now = now()->toDateTimeString();
        $matters = [];
        $parties = [];

        for ($i = 0; $i < 10000; $i++) {
            $id = (string) Str::uuid();
            $matters[] = [
                'id' => $id,
                'org_id' => $orgId,
                'matter_number' => sprintf('MAT-2026-%04d', 1000 + $i),
                'title' => $i % 3 === 0 ? "Performance matter {$i} Apex" : "Performance matter {$i}",
                'lifecycle_state' => 'ACTIVE',
                'matter_type' => 'litigation',
                'client_name' => "Perf Client {$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($i % 10 === 0) {
                $parties[] = [
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'matter_id' => $id,
                    'party_type' => 'opposing_party',
                    'name' => "Apex Holdings {$i}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($matters, 1000) as $chunk) {
            DB::table('matters')->insert($chunk);
        }
        foreach (array_chunk($parties, 1000) as $chunk) {
            DB::table('matter_parties')->insert($chunk);
        }

        $this->actingAs($admin);

        $start = microtime(true);
        $response = $this->getJson('/search/matters?q=Apex');
        $elapsed = microtime(true) - $start;

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('meta.total'));
        $this->assertLessThan(5.0, $elapsed, "10k-row search took {$elapsed}s");
    }
}
