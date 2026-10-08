<?php

namespace Tests\Feature;

use App\Events\MatterClosed;
use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\MatterParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 006 T-02 verdict tests — lifecycle transitions + closure.
 * Backend only (JSON); Blade screens are T-05.
 *
 * Named verdicts: C-01 (test_matter_crud), C-02
 * (test_lifecycle_transition_table), C-03
 * (test_intake_to_active_requires_client_party), C-14 (test_guided_closure).
 * The concurrency half of C-02 (two truly concurrent transitions) lives in
 * MatterTransitionConcurrencyTest — pcntl_fork cannot run inside a
 * RefreshDatabase transaction.
 */
class MatterLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function validCreatePayload(): array
    {
        return [
            'title' => 'Sterling v. Apex Construction',
            'matter_type' => 'litigation',
            'client_name' => 'Sterling Corp',
            'description' => 'Breach of contract.',
        ];
    }

    private function transitionAuditCount(string $matterId): int
    {
        return AuditEvent::query()
            ->where('matter_id', $matterId)
            ->whereIn('event', ['matter.transition', 'matter.closed', 'matter.reopened'])
            ->count();
    }

    private function latestTransitionAudit(string $matterId): AuditEvent
    {
        return AuditEvent::query()
            ->where('matter_id', $matterId)
            ->whereIn('event', ['matter.transition', 'matter.closed', 'matter.reopened'])
            ->orderByDesc('id')
            ->firstOrFail();
    }

    // ── C-01 ──────────────────────────────────────────────────────────────

    public function test_matter_crud(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $admin = $loader->user('user-admin');
        $viewer = $loader->user('user-viewer');
        $paralegal = $loader->user('user-paralegal');

        // 006-D03: attorney may create; viewer may not.
        $this->actingAs($attorney);
        $create = $this->postJson('/matters', $this->validCreatePayload());
        $create->assertCreated()
            ->assertJsonPath('lifecycle_state', 'INTAKE');
        $number = $create->json('matter_number');
        $this->assertMatchesRegularExpression('/^MAT-\d{4}-\d{4}$/', $number);
        $matterId = $create->json('id');

        // Numbers are unique per org.
        $second = $this->postJson('/matters', array_merge(
            $this->validCreatePayload(),
            ['title' => 'Harborview Lease Dispute', 'matter_number' => $number]
        ))->assertCreated();
        $this->assertNotSame($number, $second->json('matter_number'));

        $this->actingAs($viewer);
        $this->postJson('/matters', $this->validCreatePayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
        $this->assertTrue(
            AuditEvent::where('event', 'matter.access.denied')
                ->where('actor_id', (string) $viewer->getKey())
                ->exists()
        );

        // Update title.
        $this->actingAs($attorney);
        $this->patchJson("/matters/{$matterId}", ['title' => 'Sterling v. Apex (amended)'])
            ->assertOk()
            ->assertJsonPath('title', 'Sterling v. Apex (amended)');

        // matter_number is immutable — ignored (C-01: 422/ignored), no error.
        $this->patchJson("/matters/{$matterId}", ['matter_number' => 'MAT-2026-9999'])
            ->assertOk()
            ->assertJsonPath('matter_number', $number);

        // lifecycle_state is immutable via PATCH — ignored.
        $this->patchJson("/matters/{$matterId}", ['lifecycle_state' => 'CLOSED'])
            ->assertOk()
            ->assertJsonPath('lifecycle_state', 'INTAKE');

        // Validation: blank title, missing required fields.
        $this->patchJson("/matters/{$matterId}", ['title' => ''])
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        $this->postJson('/matters', ['title' => 'No type or client'])
            ->assertStatus(422)->assertJsonPath('code', 'validation');

        // Delete: 403 unless owner/org_admin. Paralegal holds a matter_admin
        // grant here (passes the :grant middleware) but is not the owner.
        MatterGrant::create([
            'org_id' => $attorney->org_id,
            'matter_id' => $matterId,
            'user_id' => $paralegal->getKey(),
            'role' => 'matter_admin',
            'granted_by' => $admin->getKey(),
        ]);
        $this->actingAs($paralegal);
        $this->deleteJson("/matters/{$matterId}")
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertNotSoftDeleted('matters', ['id' => $matterId]);

        // Owner deletes → soft delete hides from list and show.
        $this->actingAs($attorney);
        $this->deleteJson("/matters/{$matterId}")->assertOk();
        $this->assertSoftDeleted('matters', ['id' => $matterId]);
        $this->getJson("/matters/{$matterId}")->assertNotFound();
        $listIds = array_column($this->getJson('/matters')->assertOk()->json('data'), 'id');
        $this->assertNotContains($matterId, $listIds);

        // Restore: org admin only.
        $this->postJson("/matters/{$matterId}/restore")->assertForbidden();
        $this->actingAs($admin);
        $this->postJson("/matters/{$matterId}/restore")
            ->assertOk()->assertJsonPath('id', $matterId);
        $this->assertNotSoftDeleted('matters', ['id' => $matterId]);

        // "My Matters": the viewer (no grants) sees an empty list; the
        // attorney sees the two matters they created (auto owner grants)
        // PLUS matter-1 and matter-2, where the fixture 'owner' grants are
        // honored end-to-end since 006-D11 (previously those role strings
        // were silently ignored by the authorization layer).
        $this->actingAs($viewer);
        $this->getJson('/matters')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($attorney);
        $this->getJson('/matters')->assertOk()->assertJsonPath('meta.total', 4);

        // Restore window: deleted > 30d ago → 422 restore_window_expired.
        $this->actingAs($admin);
        $this->deleteJson("/matters/{$matterId}")->assertOk();
        Matter::withTrashed()->whereKey($matterId)->firstOrFail()
            ->forceFill(['deleted_at' => now()->subDays(31)])->save();
        $this->postJson("/matters/{$matterId}/restore")
            ->assertStatus(422)->assertJsonPath('code', 'restore_window_expired');
        $this->assertTrue(
            AuditEvent::where('event', 'matter.restore.denied')
                ->where('matter_id', $matterId)->exists()
        );

        // Audit trail for the allow path.
        foreach (['matter.created', 'matter.updated', 'matter.deleted', 'matter.restored'] as $event) {
            $this->assertTrue(
                AuditEvent::where('event', $event)->where('matter_id', $matterId)->exists(),
                "missing audit event {$event}"
            );
        }
    }

    // ── C-02 ──────────────────────────────────────────────────────────────

    public function test_lifecycle_transition_table(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $paralegal = $loader->user('user-paralegal');
        $this->actingAs($admin);

        $m1 = $loader->matter('matter-1'); // ACTIVE
        $m3 = $loader->matter('matter-3'); // INTAKE, no parties
        $m1Id = (string) $m1->getKey();
        $m3Id = (string) $m3->getKey();

        $transition = fn (string $id, array $payload) => $this->postJson("/matters/{$id}/transition", $payload);

        // Walk every defined transition green.
        $transition($m1Id, ['to' => 'DISCOVERY'])->assertOk()
            ->assertJsonPath('lifecycle_state', 'DISCOVERY');
        $audit = $this->latestTransitionAudit($m1Id);
        $this->assertSame('matter.transition', $audit->event);
        // jsonb reorders keys — order-insensitive comparison.
        $this->assertEquals(['from' => 'ACTIVE', 'to' => 'DISCOVERY', 'note' => null],
            array_intersect_key($audit->payload, ['from' => 1, 'to' => 1, 'note' => 1]));

        $transition($m1Id, ['to' => 'PRE_TRIAL'])->assertOk();
        $transition($m1Id, ['to' => 'TRIAL_SETTLEMENT'])->assertOk();

        // Invalid pair → 409 with legal next states.
        $transition($m1Id, ['to' => 'ACTIVE'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('legal_next_states', ['CLOSED']);
        $this->assertTrue(
            AuditEvent::where('event', 'matter.transition.denied')
                ->where('matter_id', $m1Id)->exists()
        );

        // Guided closure off the table: note required.
        $transition($m1Id, ['to' => 'CLOSED'])
            ->assertStatus(422)->assertJsonPath('code', 'closing_note_required');
        $transition($m1Id, ['to' => 'CLOSED', 'note' => 'Settled; file closed.'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'CLOSED');
        $closed = $this->latestTransitionAudit($m1Id);
        $this->assertSame('matter.closed', $closed->event);
        $this->assertNotNull(Matter::withTrashed()->find($m1Id)?->closed_at);

        // From CLOSED the table only allows ACTIVE and RETENTION_HOLD.
        $transition($m1Id, ['to' => 'DISCOVERY', 'note' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('legal_next_states', ['ACTIVE', 'RETENTION_HOLD']);

        // Reopen: note required, clears closed_at.
        $transition($m1Id, ['to' => 'ACTIVE'])
            ->assertStatus(422)->assertJsonPath('code', 'reopen_note_required');
        $transition($m1Id, ['to' => 'ACTIVE', 'note' => 'Reopening: new evidence.'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'ACTIVE');
        $reopened = $this->latestTransitionAudit($m1Id);
        $this->assertSame('matter.reopened', $reopened->event);
        $this->assertNull(Matter::find($m1Id)?->closed_at);

        // Brief's example: INTAKE → DISCOVERY → 409 with legal next states.
        $transition($m3Id, ['to' => 'DISCOVERY'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('legal_next_states', ['ACTIVE', 'CLOSED']);

        // 006-D06: same-state transition → 200 no-op, NO audit row.
        $before = $this->transitionAuditCount($m1Id);
        $transition($m1Id, ['to' => 'ACTIVE'])->assertOk()
            ->assertJsonPath('lifecycle_state', 'ACTIVE');
        $this->assertSame($before, $this->transitionAuditCount($m1Id));

        // Stale duplicate transition: the second identical request degrades
        // to the same-state no-op instead of a lost update.
        MatterParty::create([
            'org_id' => $admin->org_id,
            'matter_id' => $m3Id,
            'party_type' => 'client',
            'name' => 'Sterling Corp',
        ]);
        $transition($m3Id, ['to' => 'ACTIVE'])->assertOk();
        $transition($m3Id, ['to' => 'ACTIVE'])->assertOk();
        $this->assertSame(1, AuditEvent::where('matter_id', $m3Id)
            ->where('event', 'matter.transition')->count());

        // CLOSED → RETENTION_HOLD: org admin only, note required.
        $transition($m1Id, ['to' => 'CLOSED', 'note' => 'Closing again.'])->assertOk();
        // Promote the paralegal's fixture grant to matter_admin: passes the
        // :grant middleware but is not the owner and not an org admin.
        MatterGrant::query()
            ->where('matter_id', $m1Id)
            ->where('user_id', $paralegal->getKey())
            ->firstOrFail()
            ->forceFill(['role' => 'matter_admin'])
            ->save();
        $this->actingAs($paralegal);
        $transition($m1Id, ['to' => 'RETENTION_HOLD', 'note' => 'Hold it.'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($admin);
        $transition($m1Id, ['to' => 'RETENTION_HOLD', 'note' => 'Retention hold.'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'RETENTION_HOLD');

        // Phase 5 owns disposition — 006 refuses, no audit row.
        $deniedBefore = AuditEvent::where('matter_id', $m1Id)->count();
        $transition($m1Id, ['to' => 'DISPOSITION'])
            ->assertStatus(409)->assertJsonPath('code', 'disposition_not_enabled');
        $this->assertSame($deniedBefore, AuditEvent::where('matter_id', $m1Id)->count());
    }

    // ── C-03 ──────────────────────────────────────────────────────────────

    public function test_intake_to_active_requires_client_party(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $this->actingAs($attorney);

        $id = $this->postJson('/matters', $this->validCreatePayload())
            ->assertCreated()->json('id');

        // No parties at all → 422 client_party_required.
        $this->postJson("/matters/{$id}/transition", ['to' => 'ACTIVE'])
            ->assertStatus(422)->assertJsonPath('code', 'client_party_required');

        // A non-client party does not satisfy the guard.
        MatterParty::create([
            'org_id' => $attorney->org_id,
            'matter_id' => $id,
            'party_type' => 'opposing_party',
            'name' => 'Apex Construction',
        ]);
        $this->postJson("/matters/{$id}/transition", ['to' => 'ACTIVE'])
            ->assertStatus(422)->assertJsonPath('code', 'client_party_required');

        // A soft-deleted client party does not satisfy the guard either.
        $deleted = MatterParty::create([
            'org_id' => $attorney->org_id,
            'matter_id' => $id,
            'party_type' => 'client',
            'name' => 'Sterling Corp (old)',
        ]);
        $deleted->delete();
        $this->postJson("/matters/{$id}/transition", ['to' => 'ACTIVE'])
            ->assertStatus(422)->assertJsonPath('code', 'client_party_required');

        // A live client party → 200.
        MatterParty::create([
            'org_id' => $attorney->org_id,
            'matter_id' => $id,
            'party_type' => 'client',
            'name' => 'Sterling Corp',
        ]);
        $this->postJson("/matters/{$id}/transition", ['to' => 'ACTIVE'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'ACTIVE');
    }

    // ── C-14 ──────────────────────────────────────────────────────────────

    public function test_guided_closure(): void
    {
        Event::fake([MatterClosed::class]);

        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $this->actingAs($admin);

        $id = $this->postJson('/matters', $this->validCreatePayload())
            ->assertCreated()->json('id');

        // Close without a note → 422 closing_note_required.
        $this->postJson("/matters/{$id}/close", [])
            ->assertStatus(422)->assertJsonPath('code', 'closing_note_required');
        $this->postJson("/matters/{$id}/close", ['note' => '   '])
            ->assertStatus(422)->assertJsonPath('code', 'closing_note_required');

        // Guided closure → CLOSED, MatterClosed dispatched.
        $this->postJson("/matters/{$id}/close", ['note' => 'Matter concluded.'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'CLOSED');
        Event::assertDispatched(MatterClosed::class,
            fn (MatterClosed $e) => (string) $e->matter->getKey() === $id
                && $e->note === 'Matter concluded.');
        $this->assertNotNull(Matter::find($id)?->closed_at);
        $this->assertTrue(
            AuditEvent::where('event', 'matter.closed')->where('matter_id', $id)->exists()
        );

        // CLOSED is read-only: update and delete → 409 matter_closed.
        $this->patchJson("/matters/{$id}", ['title' => 'Mutated'])
            ->assertStatus(409)->assertJsonPath('code', 'matter_closed');
        $this->deleteJson("/matters/{$id}")
            ->assertStatus(409)->assertJsonPath('code', 'matter_closed');
        $this->assertTrue(
            AuditEvent::where('event', 'matter.access.denied')
                ->where('matter_id', $id)->exists()
        );

        // Reopen via transition → mutations work again.
        $this->postJson("/matters/{$id}/transition", ['to' => 'ACTIVE', 'note' => 'Reopening.'])
            ->assertOk()->assertJsonPath('lifecycle_state', 'ACTIVE');
        $this->patchJson("/matters/{$id}", ['title' => 'Mutated after reopen'])
            ->assertOk()->assertJsonPath('title', 'Mutated after reopen');

        // Summary endpoint stays readable.
        $this->getJson("/matters/{$id}/summary")
            ->assertOk()->assertJsonPath('lifecycle_state', 'ACTIVE');
    }
}
