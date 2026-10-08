<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DispositionQueue;
use App\Models\Document;
use App\Models\DocumentTombstone;
use App\Models\Matter;
use App\Models\RetentionFlag;
use App\Models\RetentionPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentFilingService;
use App\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-09 verdict: C-13 retention + legal holds.
 *
 * Named verdict: test_retention_and_holds —
 *  1. policy simulator previews matches without side effects;
 *  2. hold → hard delete → 423 hold_locked (+ purge skips held docs);
 *  3. release without the legal-hold role → 403;
 *  4. destroy needs two DISTINCT approvers → after the second approval
 *     the tombstone is retained and the versions are gone;
 *  5. a versioned policy edit keeps the old version.
 *
 * Plus: nightly evaluation flagging, extend, and archive dispositions.
 */
class DocumentRetentionHoldsTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    private Matter $matterA;

    private User $admin;

    private User $adminB;

    private User $holdOfficer;

    protected function setUp(): void
    {
        parent::setUp();

        // Blob bytes go to a temp dir in tests — never the real store.
        Config::set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/visionlaw-test-documents',
            'throw' => true,
            'report' => false,
        ]);
        Config::set('document.disk', 'documents');
        Storage::forgetDisk('documents');

        $this->loader = FixtureLoader::loadDocumentFixtures();
        $this->matterA = $this->loader->docMatter('MAT-2026-001');
        $this->admin = $this->loader->docUser('g.grant@sterling.example');

        $orgId = (string) $this->admin->org_id;

        $this->adminB = $this->makeUser($orgId, 'B. Admin', 'b.admin@sterling.example', ['org_admin']);
        // org_admin (matter access) + legal_hold (hold placement/release).
        $this->holdOfficer = $this->makeUser($orgId, 'H. Officer', 'h.officer@sterling.example', ['org_admin', 'legal_hold']);
    }

    /**
     * @param  list<string>  $roles
     */
    private function makeUser(string $orgId, string $name, string $email, array $roles): User
    {
        /** @var User $user */
        $user = User::create([
            'org_id' => $orgId,
            'name' => $name,
            'email' => $email,
            'password' => 'secret-test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        foreach ($roles as $role) {
            $user->assignRole(
                Role::query()
                    ->where('org_id', $orgId)
                    ->where('name', $role)
                    ->firstOrFail()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }

    private function docByTitle(string $title): Document
    {
        return Document::query()
            ->where('matter_id', $this->matterA->getKey())
            ->where('title', $title)
            ->firstOrFail();
    }

    // ── C-13 ──────────────────────────────────────────────────────────

    public function test_retention_and_holds(): void
    {
        $matterId = (string) $this->matterA->getKey();

        $simDoc = $this->docByTitle('Fee agreement (executed)');
        $heldDoc = $this->docByTitle('Privileged strategy memo');
        $destroyDoc = $this->docByTitle('Deposition outline (scanned)');
        $archiveDoc = $this->docByTitle('Complaint — filed stamp');

        // ── 1. Simulator previews matches without side effects ──
        $simDoc->forceFill([
            'category' => 'correspondence',
            'created_at' => now()->subDays(2),
        ])->save();

        $policy = RetentionService::createPolicy($this->admin, [
            'name' => 'Test correspondence — 1d',
            'category' => 'correspondence',
            'matter_type' => null,
            'trigger' => 'document_date',
            'disposition' => 'review',
            'legal_basis' => 'Verdict test — synthetic.',
            'period_amount' => 1,
            'period_unit' => 'days',
        ]);

        $this->assertSame('draft', $policy->status);
        $this->assertSame(1, $policy->version);

        $simulation = RetentionService::simulate($policy);

        $this->assertSame(1, $simulation['count']);
        $this->assertSame((string) $simDoc->getKey(), $simulation['documents'][0]['id']);

        // No side effects: no flags, no queue rows, no timestamp set.
        $this->assertSame(0, RetentionFlag::query()->count());
        $this->assertSame(0, DispositionQueue::query()->count());
        $this->assertNull($simDoc->fresh()->retention_flagged_at);

        // Same over HTTP as the admin.
        $this->actingAs($this->admin);
        $this->postJson("/admin/retention/policies/{$policy->getKey()}/simulate")
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->assertSame(0, RetentionFlag::query()->count());

        // ── 2. Nightly evaluation flags (idempotent), review → no queue ──
        RetentionService::activatePolicy($this->admin, $policy);

        $this->assertSame(0, Artisan::call('retention:evaluate'));
        $this->assertSame(1, RetentionFlag::query()->count());
        $this->assertNotNull($simDoc->fresh()->retention_flagged_at);
        $this->assertAuditHas('document.retention.flagged', [
            'document_id' => (string) $simDoc->getKey(),
            'policy_id' => (string) $policy->getKey(),
        ]);
        // 'review' disposition is manual — nothing auto-queued.
        $this->assertSame(0, DispositionQueue::query()->count());

        // Second run is a no-op (already flagged).
        $this->assertSame(0, Artisan::call('retention:evaluate'));
        $this->assertSame(1, RetentionFlag::query()->count());

        // ── 3. Hold → hard delete → 423; purge skips held docs ──
        $heldDocId = (string) $heldDoc->getKey();

        $this->actingAs($this->holdOfficer);
        $this->postJson("/matters/{$matterId}/documents/{$heldDocId}/hold", [
            'reason' => 'Litigation reasonably anticipated — verdict test.',
        ])->assertCreated();

        $this->actingAs($this->admin);
        $this->deleteJson("/matters/{$matterId}/documents/{$heldDocId}/permanent")
            ->assertStatus(423)
            ->assertJsonPath('code', 'hold_locked');
        $this->assertAuditHas('document.destroy.denied', [
            'document_id' => $heldDocId,
            'reason' => 'legal_hold',
        ]);

        // The trash purge respects holds too: trash it, age it, purge.
        $this->deleteJson("/matters/{$matterId}/documents/{$heldDocId}")
            ->assertOk();
        Document::withTrashed()->whereKey($heldDocId)
            ->update(['deleted_at' => now()->subDays(31)]);

        $purge = DocumentFilingService::purgeTrash();
        $this->assertSame(0, $purge['purged']);
        $this->assertSame(1, $purge['held']);
        $this->assertNotNull(Document::withTrashed()->find($heldDocId));

        // ── 4. Release without the legal-hold role → 403 ──
        $this->actingAs($this->admin); // org_admin but no legal_hold role
        $this->postJson("/matters/{$matterId}/documents/{$heldDocId}/hold/release", [
            'reason' => 'Trying without the role.',
        ])->assertForbidden();

        // Release with the role works (and is audited).
        $this->actingAs($this->holdOfficer);
        $this->postJson("/matters/{$matterId}/documents/{$heldDocId}/hold/release", [
            'reason' => 'Matter settled — verdict test.',
        ])->assertOk();
        $this->assertAuditHas('document.hold.released', ['document_id' => $heldDocId]);

        // ── 5. Destroy needs two DISTINCT approvers ──
        $destroyDocId = (string) $destroyDoc->getKey();
        $versionCount = $destroyDoc->versions()->count();
        $this->assertGreaterThan(0, $versionCount);

        $this->actingAs($this->admin);
        $entryId = $this->postJson('/admin/retention/disposition', [
            'document_id' => $destroyDocId,
            'action' => 'destroy',
            'reason' => 'Retention expired — verdict test.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        // First approval: recorded, not executed.
        $this->postJson("/admin/retention/disposition/{$entryId}/approve", [
            'note' => 'First approval.',
        ])->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.approval_count', 1);
        $this->assertNotNull(Document::find($destroyDocId));

        // Same user twice does not count.
        $this->postJson("/admin/retention/disposition/{$entryId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_approval');

        // Second DISTINCT approver executes.
        $this->actingAs($this->adminB);
        $this->postJson("/admin/retention/disposition/{$entryId}/approve", [
            'note' => 'Second approval — execute.',
        ])->assertOk();

        // Tombstone retained; blobs + versions gone.
        $tombstone = DocumentTombstone::query()
            ->where('title', 'Deposition outline (scanned)')
            ->first();
        $this->assertNotNull($tombstone);
        $this->assertSame(2, count($tombstone->approvals));
        $this->assertNull(Document::withTrashed()->find($destroyDocId));
        $this->assertSame(
            0,
            DB::table('document_versions')->where('document_id', $destroyDocId)->count()
        );
        $this->assertAuditHas('document.disposition.decided', [
            'action' => 'destroy',
            'decision' => 'executed',
        ]);
        $this->assertAuditHas('document.destroyed', ['document_id' => $destroyDocId]);

        // ── 6. Extend pushes re-flagging out; archive moves bytes cold ──
        $this->actingAs($this->admin);
        $extendId = $this->postJson('/admin/retention/disposition', [
            'document_id' => (string) $simDoc->getKey(),
            'action' => 'extend',
            'reason' => 'Still needed for the appeal.',
            'new_retention_date' => now()->addYear()->format('Y-m-d'),
        ])->assertCreated()->json('data.id');

        $this->postJson("/admin/retention/disposition/{$extendId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $simDocFresh = $simDoc->fresh();
        $this->assertNotNull($simDocFresh->retention_extended_until);
        $this->assertNull($simDocFresh->retention_flagged_at);
        $this->assertSame(
            0,
            RetentionFlag::query()->where('document_id', $simDoc->getKey())->count()
        );

        $archiveDocId = (string) $archiveDoc->getKey();
        $archiveId = $this->postJson('/admin/retention/disposition', [
            'document_id' => $archiveDocId,
            'action' => 'archive',
            'reason' => 'Closed file — cold storage.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/admin/retention/disposition/{$archiveId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertSame('archived', $archiveDoc->fresh()->status);
        $blobPaths = DB::table('document_blobs')
            ->join('document_versions', 'document_versions.blob_id', '=', 'document_blobs.id')
            ->where('document_versions.document_id', $archiveDocId)
            ->select('document_blobs.storage_path', 'document_blobs.archived')
            ->get();
        $this->assertGreaterThan(0, $blobPaths->count());
        foreach ($blobPaths as $blobRow) {
            $this->assertTrue((bool) $blobRow->archived);
            $this->assertStringStartsWith('cold/', $blobRow->storage_path);
            $this->assertTrue(Storage::disk('documents')->exists($blobRow->storage_path));
        }

        // ── 7. Versioned policy edit keeps the old version ──
        $fixturePolicy = RetentionPolicy::query()
            ->where('org_id', $this->admin->org_id)
            ->where('name', 'Closed-matter correspondence — 7y')
            ->firstOrFail();

        $this->patchJson("/admin/retention/policies/{$fixturePolicy->getKey()}", [
            'name' => $fixturePolicy->name,
            'category' => $fixturePolicy->category,
            'trigger' => 'matter_close',
            'disposition' => 'review',
            'legal_basis' => 'Updated basis — verdict test.',
            'period_amount' => 10,
            'period_unit' => 'years',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        // The old version row is retained untouched.
        $this->assertSame(
            2,
            RetentionPolicy::query()
                ->where('org_id', $this->admin->org_id)
                ->where('name', $fixturePolicy->name)
                ->count()
        );
        $this->assertSame(
            'active',
            $fixturePolicy->fresh()->status,
            'v1 stays active until v2 is activated'
        );

        $v2 = RetentionPolicy::query()
            ->where('org_id', $this->admin->org_id)
            ->where('name', $fixturePolicy->name)
            ->where('version', 2)
            ->firstOrFail();

        $this->postJson("/admin/retention/policies/{$v2->getKey()}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('superseded', $fixturePolicy->fresh()->status);
        $this->assertSame('active', $v2->fresh()->status);
    }

    /**
     * Assert an audit event was recorded whose payload contains the
     * expected subset (mirrors DocumentFilingTrashTest's helper).
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertAuditHas(string $event, array $expected): void
    {
        $events = AuditEvent::query()->where('event', $event)->get();

        foreach ($events as $auditEvent) {
            $payload = $auditEvent->payload;
            $match = true;
            foreach ($expected as $key => $value) {
                if (($payload[$key] ?? null) !== $value) {
                    $match = false;

                    break;
                }
            }
            if ($match) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("No audit event '{$event}' with payload ".json_encode($expected).' found.');
    }
}
