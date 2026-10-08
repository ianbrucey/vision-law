<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\MatterComment;
use App\Models\MatterDocumentLog;
use App\Models\MatterLink;
use App\Models\MatterParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 006 T-03 verdict tests — parties, comments, links, document log.
 * Backend only (JSON).
 *
 * Named verdicts: C-04 (test_party_crud), C-05
 * (test_document_log_append_only), C-06 (test_comment_thread_rules), C-12
 * (test_related_matters).
 */
class MatterCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private function createMatterPayload(): array
    {
        return [
            'title' => 'Collaboration test matter',
            'matter_type' => 'litigation',
            'client_name' => 'Collaboration Client Co.',
        ];
    }

    // ── C-04 ──────────────────────────────────────────────────────────────

    public function test_party_crud(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $paralegal = $loader->user('user-paralegal');

        $this->actingAs($attorney);
        $matterId = $this->postJson('/matters', $this->createMatterPayload())
            ->assertCreated()
            ->json('id');

        // One of each of the 7 party types, with contact fields + user link.
        $types = ['client', 'opposing_party', 'opposing_counsel', 'witness', 'expert', 'court', 'other'];

        foreach ($types as $i => $type) {
            $this->postJson("/matters/{$matterId}/parties", [
                'party_type' => $type,
                'name' => "Party {$type}",
                'role_description' => "Role for {$type}",
                'email' => "party{$i}@example.com",
                'phone' => "555-010{$i}",
                'address' => "{$i} Main St, Long Beach, CA",
                'user_id' => (string) $paralegal->getKey(),
            ])->assertCreated()->assertJsonPath('party_type', $type);
        }

        $this->assertSame(7, MatterParty::where('matter_id', $matterId)->count());

        // Soft delete hides from the list but the row survives.
        $first = MatterParty::where('matter_id', $matterId)->firstOrFail();

        $this->deleteJson("/matters/{$matterId}/parties/{$first->getKey()}")
            ->assertOk()
            ->assertJsonPath('id', (string) $first->getKey());

        $this->assertSame(6, MatterParty::where('matter_id', $matterId)->count());
        $this->assertSame(7, MatterParty::withTrashed()->where('matter_id', $matterId)->count());

        // Unknown party type → 422.
        $this->postJson("/matters/{$matterId}/parties", [
            'party_type' => 'bogus',
            'name' => 'X',
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        // Audit: added + removed.
        $this->assertTrue(AuditEvent::where('event', 'matter.party.added')
            ->where('matter_id', $matterId)->exists());
        $this->assertTrue(AuditEvent::where('event', 'matter.party.removed')
            ->where('matter_id', $matterId)->exists());
    }

    // ── C-05 ──────────────────────────────────────────────────────────────

    public function test_document_log_append_only(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');

        $this->actingAs($attorney);
        $matterId = $this->postJson('/matters', $this->createMatterPayload())
            ->assertCreated()
            ->json('id');

        $logId = $this->postJson("/matters/{$matterId}/document-log", [
            'direction' => 'received',
            'counterparty' => 'Apex Inc',
            'logged_at' => '2026-10-01',
            'method' => 'email',
            'notes' => 'Initial disclosures',
        ])->assertCreated()->json('id');

        // Annotations are the only mutable field.
        $this->patchJson("/matters/{$matterId}/document-log/{$logId}", [
            'annotations' => ['reviewed' => true, 'reviewer' => 'A. Attorney'],
        ])->assertOk()
            ->assertJsonPath('annotations.reviewed', true)
            ->assertJsonPath('counterparty', 'Apex Inc');

        // Core fields are immutable — rejected, row unchanged.
        $this->patchJson("/matters/{$matterId}/document-log/{$logId}", [
            'counterparty' => 'Evil Corp',
        ])->assertStatus(422)->assertJsonPath('code', 'validation');

        $this->assertSame('Apex Inc', MatterDocumentLog::find($logId)->counterparty);

        // No delete route exists.
        $this->deleteJson("/matters/{$matterId}/document-log/{$logId}")->assertStatus(405);

        // A correction is a new row.
        $this->postJson("/matters/{$matterId}/document-log", [
            'direction' => 'received',
            'counterparty' => 'Apex Inc (corrected)',
            'logged_at' => '2026-10-02',
            'method' => 'email',
        ])->assertCreated();

        $this->assertSame(2, MatterDocumentLog::where('matter_id', $matterId)->count());
        $this->assertTrue(AuditEvent::where('event', 'matter.document_logged')
            ->where('matter_id', $matterId)->exists());
    }

    // ── C-06 ──────────────────────────────────────────────────────────────

    public function test_comment_thread_rules(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $paralegal = $loader->user('user-paralegal');
        $viewer = $loader->user('user-viewer');
        $admin = $loader->user('user-admin');
        $matter = $loader->matter('matter-1');
        $mid = (string) $matter->getKey();

        $this->actingAs($attorney);

        $topId = $this->postJson("/matters/{$mid}/comments", ['body' => 'Top-level note'])
            ->assertCreated()
            ->json('id');

        $replyId = $this->postJson("/matters/{$mid}/comments", [
            'body' => 'A reply',
            'parent_id' => $topId,
        ])->assertCreated()->json('id');

        // Reply to a reply → 422 reply_depth_exceeded.
        $this->postJson("/matters/{$mid}/comments", [
            'body' => 'Nested reply',
            'parent_id' => $replyId,
        ])->assertStatus(422)->assertJsonPath('code', 'reply_depth_exceeded');

        // Edit within 24h → 200 with the edited badge.
        $edited = $this->patchJson("/matters/{$mid}/comments/{$topId}", ['body' => 'Edited note'])
            ->assertOk()
            ->assertJsonPath('body', 'Edited note');
        $this->assertNotNull($edited->json('edited_at'));

        // Edit at 25h → 422 edit_window_expired (fixture c2, author paralegal).
        $this->actingAs($paralegal);
        $this->patchJson("/matters/{$mid}/comments/{$loader->id('c2')}", ['body' => 'Too late'])
            ->assertStatus(422)->assertJsonPath('code', 'edit_window_expired');

        // A granted non-author without manage rights cannot edit → 403.
        $this->actingAs($admin);
        $this->postJson("/matters/{$mid}/assignments", [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertCreated();

        $this->actingAs($viewer);
        $this->patchJson("/matters/{$mid}/comments/{$topId}", ['body' => 'Hijack'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        // Delete → tombstone: row and author retained.
        $this->actingAs($attorney);
        $this->deleteJson("/matters/{$mid}/comments/{$topId}")
            ->assertOk()
            ->assertJsonPath('tombstone', true);

        $tombstone = MatterComment::withTrashed()->find($topId);
        $this->assertNotNull($tombstone);
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertSame((string) $attorney->getKey(), (string) $tombstone->author_id);

        // @mention of an assigned user writes the matter.mentioned audit.
        $this->postJson("/matters/{$mid}/comments", [
            'body' => 'Please review @p.paralegal@sterling.example when you can.',
        ])->assertCreated();

        $mentioned = AuditEvent::where('event', 'matter.mentioned')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertContains(
            (string) $paralegal->getKey(),
            $mentioned->payload['mentioned_user_ids']
        );
    }

    // ── C-12 ──────────────────────────────────────────────────────────────

    public function test_related_matters(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $paralegal = $loader->user('user-paralegal');
        $viewer = $loader->user('user-viewer');
        $admin = $loader->user('user-admin');
        $m1 = $loader->matter('matter-1');
        $m2 = $loader->matter('matter-2');
        $m1Id = (string) $m1->getKey();
        $m2Id = (string) $m2->getKey();

        // Canonical ordering is service-enforced (matter_id < related_matter_id).
        $link = MatterLink::firstOrFail();
        $this->assertTrue((string) $link->matter_id < (string) $link->related_matter_id);

        $this->actingAs($attorney);

        // The fixture link appears on both sides, with full metadata for a
        // granted actor.
        $sideA = $this->getJson("/matters/{$m1Id}/links")->assertOk()->json('data');
        $this->assertCount(1, $sideA);
        $this->assertSame('Harborview Lease Dispute', $sideA[0]['related_matter']['title']);

        $sideB = $this->getJson("/matters/{$m2Id}/links")->assertOk()->json('data');
        $this->assertCount(1, $sideB);
        $this->assertSame('Sterling v. Apex Construction', $sideB[0]['related_matter']['title']);

        // Self-link → 422 self_link.
        $this->postJson("/matters/{$m1Id}/links", [
            'related_matter_id' => $m1Id,
            'link_type' => 'other',
        ])->assertStatus(422)->assertJsonPath('code', 'self_link');

        // Duplicate link → idempotent 200, still one row.
        $this->postJson("/matters/{$m1Id}/links", [
            'related_matter_id' => $m2Id,
            'link_type' => 'same_client',
        ])->assertOk();
        $this->assertSame(1, MatterLink::count());

        // Remove → gone from both sides.
        $this->deleteJson("/matters/{$m1Id}/links/{$link->getKey()}")->assertOk();
        $this->assertSame([], $this->getJson("/matters/{$m1Id}/links")->assertOk()->json('data'));

        // Re-link for the scoping assertions below.
        $this->postJson("/matters/{$m1Id}/links", [
            'related_matter_id' => $m2Id,
            'link_type' => 'same_client',
        ])->assertCreated();

        // A user with a grant on A but not B sees "restricted matter" —
        // no title, no metadata (leak sentinel).
        $this->actingAs($admin);
        $this->postJson("/matters/{$m1Id}/assignments", [
            'user_id' => (string) $viewer->getKey(),
            'role' => 'viewer',
        ])->assertCreated();

        $this->actingAs($viewer);
        $restricted = $this->getJson("/matters/{$m1Id}/links")->assertOk();
        $this->assertTrue($restricted->json('data.0.related_matter.restricted') ?? false);
        $restricted->assertDontSee('Harborview Lease Dispute');

        // Linking TO a matter the actor cannot access → 404, no leak
        // (paralegal: editor on m1, no grant on m2).
        $this->actingAs($paralegal);
        $this->postJson("/matters/{$m1Id}/links", [
            'related_matter_id' => $m2Id,
            'link_type' => 'other',
        ])->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
