<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\MatterComment;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 006 T-06 verdict tests — authorization matrix + activity feed.
 * Backend only (JSON).
 *
 * Named verdicts: C-07 (test_activity_feed), C-13 (test_authorization_matrix,
 * test_leak_sentinels_for_denied_actors).
 *
 * The matrix covers every 006 route × {org_admin, attorney, paralegal,
 * viewer, outside_counsel, unassigned, other-org, anonymous} with the
 * expected 200/403/404 per 03-contract.md §Role-visible data, and asserts
 * the leak sentinels never reach denied actors (HTML/JSON bodies and the
 * appended log output).
 */
class MatterAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    private Matter $targetA; // granted matter (owner/editor/viewer/OC grants)

    private Matter $targetC; // second granted matter (link target)

    private Matter $targetB; // no grants — unassigned / cross-org target

    /** @var list<string> */
    private const SENTINELS = [
        'Sterling v. Apex Construction',
        'Harborview Lease Dispute',
        'Meridian Internal Review',
        'Rival Confidential Matter',
        'Sterling Manufacturing Co.',
        'Apex Construction Inc.',
        'J. Kennamer',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = FixtureLoader::loadMatterFixtures();

        $org = $this->loader->org('org-sterling');
        $orgId = (string) $org->getKey();
        $admin = $this->loader->user('user-admin');
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $viewer = $this->loader->user('user-viewer');
        $oc = $this->loader->user('user-oc');

        $mk = fn (string $title): Matter => MatterService::createMatter($orgId, [
            'title' => $title,
            'matter_type' => 'litigation',
            'client_name' => 'Matrix Client Co.',
        ], $admin);

        $this->targetA = $mk('Matrix target matter A');
        $this->targetC = $mk('Matrix target matter C');
        $this->targetB = $mk('Matrix target matter B');

        // targetA/targetC: INTAKE with a client party, grants per contract roles.
        foreach ([$this->targetA, $this->targetC] as $matter) {
            MatterService::addParty($matter, [
                'party_type' => 'client',
                'name' => 'Matrix Client Party',
            ], $admin);
            MatterService::assignUser($matter, (string) $attorney->getKey(), 'owner', null, $admin);
            MatterService::assignUser($matter, (string) $paralegal->getKey(), 'editor', null, $admin);
            MatterService::assignUser($matter, (string) $viewer->getKey(), 'viewer', null, $admin);
            MatterService::assignUser($matter, (string) $oc->getKey(), 'outside_counsel', null, $admin);
        }

        // A comment by the admin on targetA — the viewer's update/destroy
        // attempts target it (not author, not manage → 403).
        MatterService::addComment($this->targetA, ['body' => 'Admin seed comment'], $admin);
    }

    // ── C-07 ──────────────────────────────────────────────────────────────

    public function test_activity_feed(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');

        $this->actingAs($attorney);
        $matterId = $this->postJson('/matters', [
            'title' => 'Feed test matter',
            'matter_type' => 'litigation',
            'client_name' => 'Feed Client Co.',
        ])->assertCreated()->json('id');

        $this->postJson("/matters/{$matterId}/parties", [
            'party_type' => 'client',
            'name' => 'Feed Client Party',
        ])->assertCreated();

        $this->postJson("/matters/{$matterId}/transition", ['to' => 'ACTIVE'])->assertOk();

        $this->postJson("/matters/{$matterId}/comments", ['body' => 'Feed comment'])->assertCreated();

        $this->postJson("/matters/{$matterId}/assignments", [
            'user_id' => (string) $paralegal->getKey(),
            'role' => 'editor',
        ])->assertCreated();

        // Full feed: create/transition/comment/assign each appear exactly once.
        $feed = $this->getJson("/matters/{$matterId}/feed")->assertOk();
        $events = $feed->json('data');
        $names = array_column($events, 'event');

        $this->assertSame(5, $feed->json('meta.total'));
        $this->assertCount(1, array_keys($names, 'matter.created'));
        $this->assertCount(1, array_keys($names, 'matter.party.added'));
        $this->assertCount(1, array_keys($names, 'matter.transition'));
        $this->assertCount(1, array_keys($names, 'matter.comment.added'));
        $this->assertCount(1, array_keys($names, 'matter.assigned'));

        // Newest-first.
        $created = array_column($events, 'created_at');
        $sorted = $created;
        rsort($sorted);
        $this->assertSame($sorted, $created);
        $this->assertSame('matter.assigned', $names[0]);

        // ?type= filter.
        $byType = $this->getJson("/matters/{$matterId}/feed?type=matter.comment.added")->assertOk();
        $this->assertSame(1, $byType->json('meta.total'));
        $this->assertSame('matter.comment.added', $byType->json('data.0.event'));

        // ?actor= filter — the attorney performed all five actions.
        $byActor = $this->getJson("/matters/{$matterId}/feed?actor=".(string) $attorney->getKey())->assertOk();
        $this->assertSame(5, $byActor->json('meta.total'));
        foreach ($byActor->json('data') as $row) {
            $this->assertSame((string) $attorney->getKey(), $row['actor']['id']);
        }

        // Combined filters + pagination: page 2 is disjoint from page 1.
        $page1 = $this->getJson("/matters/{$matterId}/feed?per_page=2")->assertOk()->json('data');
        $page2 = $this->getJson("/matters/{$matterId}/feed?per_page=2&page=2")->assertOk()->json('data');
        $page3 = $this->getJson("/matters/{$matterId}/feed?per_page=2&page=3")->assertOk()->json('data');

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertCount(1, $page3);
        $this->assertEmpty(array_intersect(
            array_column($page1, 'id'),
            array_column($page2, 'id')
        ));

        // An actor without a grant cannot read the feed (404, no leak).
        $this->actingAs($this->loader->user('user-rival'));
        $denied = $this->getJson("/matters/{$matterId}/feed")->assertNotFound();
        $this->assertNoSentinels($denied);
    }

    // ── C-13 ──────────────────────────────────────────────────────────────

    public function test_authorization_matrix(): void
    {
        $admin = $this->loader->user('user-admin');
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $viewer = $this->loader->user('user-viewer');
        $oc = $this->loader->user('user-oc');
        $rival = $this->loader->user('user-rival');

        // actor key => [user|null, target matter]
        $actors = [
            'admin' => [$admin, $this->targetA],
            'attorney' => [$attorney, $this->targetA],
            'paralegal' => [$paralegal, $this->targetA],
            'viewer' => [$viewer, $this->targetA],
            'oc' => [$oc, $this->targetA],
            'unassigned' => [$viewer, $this->targetB],
            'rival' => [$rival, $this->targetB],
            'anonymous' => [null, $this->targetA],
        ];

        // Expectations per route, keyed by actor key. 200-family = allowed;
        // 403 = matter visible, role insufficient; 404 = no existence leak.
        // Anonymous over JSON: 401 (machine equivalent of the contract's
        // "302→login"), asserted explicitly separately.
        $ok = ['admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 200, 'oc' => 200,
            'unassigned' => 404, 'rival' => 404, 'anonymous' => 401];
        $edit = ['admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 403, 'oc' => 403,
            'unassigned' => 404, 'rival' => 404, 'anonymous' => 401];
        $manage = ['admin' => 200, 'attorney' => 200, 'paralegal' => 403, 'viewer' => 403, 'oc' => 403,
            'unassigned' => 404, 'rival' => 404, 'anonymous' => 401];
        $comment = ['admin' => 201, 'attorney' => 201, 'paralegal' => 201, 'viewer' => 403, 'oc' => 201,
            'unassigned' => 404, 'rival' => 404, 'anonymous' => 401];

        foreach ($actors as $key => [$user, $target]) {
            $this->runAs($user);
            $tid = (string) $target->getKey();

            // GET /matters — every authenticated user lists; the result set
            // is scoped server-side ("My Matters"), so unassigned/other-org
            // get 200 with an empty set, never 404.
            $this->req('GET', '/matters', [], $key === 'anonymous' ? 401 : 200, $key);

            // GET /matters/create — 006-D03: attorney, org_admin, paralegal.
            $this->req('GET', '/matters/create', [], [
                'admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 403, 'oc' => 403,
                'unassigned' => 403, 'rival' => 200, 'anonymous' => 401,
            ][$key], $key);

            // POST /matters — same create gate; rival creates in their own org.
            $this->req('POST', '/matters', [
                'title' => "Matrix matter by {$key}",
                'matter_type' => 'litigation',
                'client_name' => 'Matrix Client Co.',
            ], [
                'admin' => 201, 'attorney' => 201, 'paralegal' => 201, 'viewer' => 403, 'oc' => 403,
                'unassigned' => 403, 'rival' => 201, 'anonymous' => 401,
            ][$key], $key);

            // Matter-scoped reads.
            $this->req('GET', "/matters/{$tid}", [], $ok[$key], $key);
            $this->req('GET', "/matters/{$tid}/edit", [], $edit[$key], $key);
            $this->req('PATCH', "/matters/{$tid}", ['title' => "Renamed by {$key}"], $edit[$key], $key);
            $this->req('GET', "/matters/{$tid}/summary", [], $ok[$key], $key);
            $this->req('GET', "/matters/{$tid}/feed", [], $ok[$key], $key);
            $this->req('GET', '/search/matters?q=Matrix', [], [
                'admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 200, 'oc' => 200,
                'unassigned' => 200, 'rival' => 200, 'anonymous' => 401,
            ][$key], $key);

            // Parties (:edit).
            $partyId = $this->isAllowed($edit[$key])
                ? $this->req('POST', "/matters/{$tid}/parties", [
                    'party_type' => 'witness', 'name' => "Witness {$key}",
                ], 201, $key)->json('id')
                : '00000000-0000-0000-0000-000000000000';
            $this->req('POST', "/matters/{$tid}/parties", [
                'party_type' => 'witness', 'name' => "Witness {$key}",
            ], ['admin' => 201, 'attorney' => 201, 'paralegal' => 201, 'viewer' => 403, 'oc' => 403,
                'unassigned' => 404, 'rival' => 404, 'anonymous' => 401][$key], $key);
            $this->req('DELETE', "/matters/{$tid}/parties/{$partyId}", [], $edit[$key], $key);

            // Comments (:comment floor; author-or-manage on update/destroy).
            $commentId = null;
            if (in_array($key, ['admin', 'attorney', 'paralegal', 'oc'], true)) {
                $commentId = $this->req('POST', "/matters/{$tid}/comments", [
                    'body' => "Comment by {$key}",
                ], 201, $key)->json('id');
            } else {
                $this->req('POST', "/matters/{$tid}/comments", [
                    'body' => "Comment by {$key}",
                ], $comment[$key], $key);
            }
            // Viewer attempts against the admin's seeded comment.
            $targetComment = $commentId ?? $this->adminSeedCommentId();
            $this->req('PATCH', "/matters/{$tid}/comments/{$targetComment}", [
                'body' => "Edited by {$key}",
            ], ['admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 403, 'oc' => 200,
                'unassigned' => 404, 'rival' => 404, 'anonymous' => 401][$key], $key);
            $this->req('DELETE', "/matters/{$tid}/comments/{$targetComment}", [], [
                'admin' => 200, 'attorney' => 200, 'paralegal' => 200, 'viewer' => 403, 'oc' => 200,
                'unassigned' => 404, 'rival' => 404, 'anonymous' => 401,
            ][$key], $key);

            // Timeline read + links index (:view).
            $this->req('POST', "/matters/{$tid}/timeline/read", [], $ok[$key], $key);
            $this->req('GET', "/matters/{$tid}/links", [], $ok[$key], $key);

            // Links (:edit). addLink is idempotent — 200 on re-link.
            $linkId = null;
            if ($this->isAllowed($edit[$key])) {
                $resp = $this->req('POST', "/matters/{$tid}/links", [
                    'related_matter_id' => (string) $this->targetC->getKey(),
                    'link_type' => 'same_client',
                ], null, $key);
                $this->assertContains($resp->status(), [200, 201]);
                $linkId = $resp->json('id');
            } else {
                $this->req('POST', "/matters/{$tid}/links", [
                    'related_matter_id' => (string) $this->targetC->getKey(),
                    'link_type' => 'same_client',
                ], ['viewer' => 403, 'oc' => 403, 'unassigned' => 404, 'rival' => 404,
                    'anonymous' => 401][$key], $key);
            }
            $this->req('DELETE', "/matters/{$tid}/links/".($linkId ?? '00000000-0000-0000-0000-000000000000'), [], $edit[$key], $key);

            // Document log (:edit).
            $logId = $this->isAllowed($edit[$key])
                ? $this->req('POST', "/matters/{$tid}/document-log", [
                    'direction' => 'received',
                    'counterparty' => 'Matrix Counterparty',
                    'logged_at' => '2026-10-06',
                    'method' => 'email',
                ], 201, $key)->json('id')
                : '00000000-0000-0000-0000-000000000000';
            $this->req('POST', "/matters/{$tid}/document-log", [
                'direction' => 'received',
                'counterparty' => 'Matrix Counterparty',
                'logged_at' => '2026-10-06',
                'method' => 'email',
            ], ['admin' => 201, 'attorney' => 201, 'paralegal' => 201, 'viewer' => 403, 'oc' => 403,
                'unassigned' => 404, 'rival' => 404, 'anonymous' => 401][$key], $key);
            $this->req('PATCH', "/matters/{$tid}/document-log/{$logId}", [
                'annotations' => ['reviewed' => true],
            ], $edit[$key], $key);

            // Assignments (:manage). Fresh assignee per actor — no grant clashes.
            $grantId = null;
            if ($this->isAllowed($manage[$key])) {
                $assignee = User::factory()->create([
                    'org_id' => (string) $this->loader->org('org-sterling')->getKey(),
                ]);
                $grantId = $this->req('POST', "/matters/{$tid}/assignments", [
                    'user_id' => (string) $assignee->getKey(),
                    'role' => 'viewer',
                ], 201, $key)->json('id');
            } else {
                $this->req('POST', "/matters/{$tid}/assignments", [
                    'user_id' => (string) $this->loader->user('user-viewer')->getKey(),
                    'role' => 'viewer',
                ], ['paralegal' => 403, 'viewer' => 403, 'oc' => 403,
                    'unassigned' => 404, 'rival' => 404, 'anonymous' => 401][$key], $key);
            }
            $this->req('DELETE', "/matters/{$tid}/assignments/".($grantId ?? '00000000-0000-0000-0000-000000000000'), [], $manage[$key], $key);

            // Lifecycle (:manage) — fresh INTAKE matter per actor (transition
            // and close are one-way per matter).
            $lcMatter = $this->freshLifecycleMatter($key);
            $lid = (string) $lcMatter->getKey();
            $this->req('POST', "/matters/{$lid}/transition", ['to' => 'ACTIVE'], $manage[$key], $key);
            $this->req('POST', "/matters/{$lid}/close", ['note' => "Closing ({$key})"], $manage[$key], $key);

            // Restore — fresh soft-deleted matter per actor; org_admin only.
            $restoreTarget = $this->freshDeletedMatter();
            $rid = (string) $restoreTarget->getKey();
            $this->req('POST', "/matters/{$rid}/restore", [], [
                'admin' => 200, 'attorney' => 403, 'paralegal' => 403, 'viewer' => 403, 'oc' => 403,
                'unassigned' => 403, 'rival' => 404, 'anonymous' => 401,
            ][$key], $key);

            // Destroy — fresh matter per actor, granted the actor's role.
            $destroyTarget = $this->freshGrantedMatter($key);
            $did = (string) $destroyTarget->getKey();
            $this->req('DELETE', "/matters/{$did}", [], $manage[$key], $key);
        }

        // The contract's "302→login" for anonymous, over a plain (non-JSON) request.
        $this->get("/matters/{$this->targetA->getKey()}")->assertRedirect('/login');
    }

    // ── C-13 leak sentinels ───────────────────────────────────────────────

    public function test_leak_sentinels_for_denied_actors(): void
    {
        $viewer = $this->loader->user('user-viewer');
        $oc = $this->loader->user('user-oc');
        $rival = $this->loader->user('user-rival');
        $paralegal = $this->loader->user('user-paralegal');

        $m1 = (string) $this->loader->matter('matter-1')->getKey(); // Sterling v. Apex Construction
        $m2 = (string) $this->loader->matter('matter-2')->getKey(); // Harborview Lease Dispute
        $m3 = (string) $this->loader->matter('matter-3')->getKey(); // Meridian Internal Review
        $mr = (string) $this->loader->matter('matter-rival')->getKey(); // Rival Confidential Matter

        $logPath = storage_path('logs/laravel.log');
        $logBefore = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        // [actor, matter id, expected] — every pair must deny without leaking.
        $probes = [
            [$viewer, $m1, 404], // no grant at all
            [$viewer, $m2, 404], // expired grant == no grant
            [$oc, $m2, 404],     // OC granted matter-1 only
            [$oc, $m3, 404],
            [$paralegal, $m2, 404], // editor on matter-1 only
            [$rival, $m1, 404],   // cross-org
            [$rival, $mr, 404],  // rival holds no grant even on their own org's matter
        ];

        foreach ($probes as [$actor, $mid, $expected]) {
            $this->actingAs($actor);

            foreach (['', '/summary', '/feed', '/links'] as $suffix) {
                $resp = $this->getJson("/matters/{$mid}{$suffix}");
                $resp->assertStatus($expected);
                $this->assertNoSentinels($resp);
            }
        }

        // Outside counsel sees matter-1's link to matter-2 as {restricted: true}
        // — no title, no metadata.
        $this->actingAs($oc);
        $links = $this->getJson("/matters/{$m1}/links")->assertOk();
        $body = $links->getContent();
        $this->assertStringContainsString('"restricted":true', $body);
        $this->assertStringNotContainsString('Harborview Lease Dispute', $body);

        // Anonymous over JSON: denied; over HTML: 302 → login.
        $this->app['auth']->logout();
        $anonShow = $this->getJson("/matters/{$m1}");
        $this->assertContains($anonShow->status(), [401, 404]);
        $this->assertNoSentinels($anonShow);
        $this->get("/matters/{$m1}")->assertRedirect('/login');

        // Nothing appended to the log may carry a sentinel for these probes.
        $logAfter = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        $appended = substr($logAfter, strlen($logBefore));
        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $appended,
                "Leak sentinel in logs for a denied actor: {$sentinel}"
            );
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function runAs(?User $user): void
    {
        if ($user === null) {
            $this->app['auth']->logout();
        } else {
            $this->actingAs($user);
        }
    }

    /**
     * Issue a JSON request as the current actor and assert the expected
     * status. A null $expected skips the assertion (caller asserts itself).
     * Every 404 body is additionally asserted to carry no existence leak.
     */
    private function req(string $method, string $url, array $payload, ?int $expected, string $actorKey): TestResponse
    {
        $resp = match ($method) {
            'GET' => $this->getJson($url),
            'POST' => $this->postJson($url, $payload),
            'PATCH' => $this->patchJson($url, $payload),
            'DELETE' => $this->deleteJson($url),
        };

        if ($expected !== null) {
            $this->assertSame(
                $expected,
                $resp->status(),
                "actor={$actorKey} {$method} {$url} expected={$expected} body=".substr($resp->getContent(), 0, 300)
            );
        }

        if ($resp->status() === 404) {
            $this->assertStringNotContainsString(
                'Matrix target matter',
                $resp->getContent(),
                "Existence leak for actor {$actorKey} on {$method} {$url}"
            );
        }

        return $resp;
    }

    private function isAllowed(int $expected): bool
    {
        return $expected < 300;
    }

    private function adminSeedCommentId(): string
    {
        return (string) MatterComment::query()
            ->where('matter_id', $this->targetA->getKey())
            ->where('body', 'Admin seed comment')
            ->value('id');
    }

    /**
     * Fresh INTAKE matter with a client party, granted the actor's role —
     * for the transition/close leg (each matter transitions/closes once).
     */
    private function freshLifecycleMatter(string $actorKey): Matter
    {
        $admin = $this->loader->user('user-admin');
        $matter = MatterService::createMatter(
            (string) $this->loader->org('org-sterling')->getKey(),
            ['title' => "Matrix lifecycle target ({$actorKey})", 'matter_type' => 'litigation', 'client_name' => 'Matrix Client Co.'],
            $admin
        );
        MatterService::addParty($matter, ['party_type' => 'client', 'name' => 'Lifecycle Client'], $admin);

        $role = match ($actorKey) {
            'attorney' => 'owner',
            'paralegal' => 'editor',
            'viewer' => 'viewer',
            'oc' => 'outside_counsel',
            default => null,
        };

        if ($role !== null) {
            $user = $this->loader->user(match ($actorKey) {
                'attorney' => 'user-attorney',
                'paralegal' => 'user-paralegal',
                'viewer' => 'user-viewer',
                'oc' => 'user-oc',
            });
            MatterService::assignUser($matter, (string) $user->getKey(), $role, null, $admin);
        }

        return $matter;
    }

    private function freshDeletedMatter(): Matter
    {
        $admin = $this->loader->user('user-admin');
        $matter = MatterService::createMatter(
            (string) $this->loader->org('org-sterling')->getKey(),
            ['title' => 'Matrix restore target', 'matter_type' => 'litigation', 'client_name' => 'Matrix Client Co.'],
            $admin
        );
        MatterService::deleteMatter($matter, $admin);

        return $matter;
    }

    private function freshGrantedMatter(string $actorKey): Matter
    {
        $admin = $this->loader->user('user-admin');
        $matter = MatterService::createMatter(
            (string) $this->loader->org('org-sterling')->getKey(),
            ['title' => "Matrix destroy target ({$actorKey})", 'matter_type' => 'litigation', 'client_name' => 'Matrix Client Co.'],
            $admin
        );

        $role = match ($actorKey) {
            'attorney' => 'owner',
            'paralegal' => 'editor',
            'viewer', 'unassigned' => 'viewer',
            'oc' => 'outside_counsel',
            default => null,
        };

        if ($role !== null) {
            $user = $actorKey === 'unassigned'
                ? $this->loader->user('user-viewer')
                : $this->loader->user(match ($actorKey) {
                    'attorney' => 'user-attorney',
                    'paralegal' => 'user-paralegal',
                    'viewer' => 'user-viewer',
                    'oc' => 'user-oc',
                });
            // 'unassigned' gets no grant — that is the point of the actor.
            if ($actorKey !== 'unassigned') {
                MatterService::assignUser($matter, (string) $user->getKey(), $role, null, $admin);
            }
        }

        return $matter;
    }

    private function assertNoSentinels(TestResponse $resp): void
    {
        $this->assertStringNotContainsStringIgnoringCaseBatch($resp->getContent());
    }

    private function assertStringNotContainsStringIgnoringCaseBatch(string $haystack): void
    {
        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $haystack,
                "Leak sentinel reached a denied actor: {$sentinel}"
            );
        }
    }
}
