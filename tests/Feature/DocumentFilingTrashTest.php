<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentStore;
use App\Services\MatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-05 verdict: C-08 filing + trash.
 *
 * Named verdict: test_filing_and_trash — move doc between folders;
 * move-matter → versions + audit intact; delete → trash; restore → all
 * versions back; hold → hard delete → 423.
 *
 * Document rows come from the canonical 007 fixtures (loaded through
 * T-02's ingest pipeline) plus a small local helper for rows the
 * fixtures don't cover (e.g. matter B documents).
 */
class DocumentFilingTrashTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    private Matter $matterA;

    private Matter $matterB;

    private User $admin;

    private User $noGrant;

    private DocumentFolder $pleadings;

    private DocumentFolder $discovery;

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
        $this->matterB = $this->loader->docMatter('MAT-2026-002');
        $this->admin = $this->loader->docUser('g.grant@sterling.example'); // org_admin → implicit matter_owner
        $this->noGrant = $this->loader->docUser('nogrant@sterling.example'); // viewer, no matter grant

        $this->pleadings = DocumentFolder::query()
            ->where('matter_id', $this->matterA->getKey())
            ->where('name', 'Pleadings')
            ->firstOrFail();
        $this->discovery = DocumentFolder::query()
            ->where('matter_id', $this->matterA->getKey())
            ->where('name', 'Discovery')
            ->firstOrFail();
    }

    // ── C-08 ──────────────────────────────────────────────────────────

    public function test_filing_and_trash(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matterA->getKey();
        $matterBId = (string) $this->matterB->getKey();

        // Canonical fixture: 3 versions, filed in Pleadings.
        $doc = Document::query()
            ->where('matter_id', $this->matterA->getKey())
            ->where('title', 'Complaint — filed stamp')
            ->firstOrFail();
        $docId = (string) $doc->getKey();
        $this->assertSame(3, $doc->versions()->count());

        // 1. Move between folders of the same matter.
        $this->postJson("/matters/{$matterId}/documents/{$docId}/move", [
            'folder_id' => (string) $this->discovery->getKey(),
        ])->assertOk()->assertJsonPath('data.folder_id', (string) $this->discovery->getKey());

        $this->assertSame((string) $this->discovery->getKey(), (string) $doc->fresh()->folder_id);
        $this->assertAuditHas('document.moved', ['document_id' => $docId]);

        // 2. Re-file to another matter: versions + audit travel with it.
        $this->postJson("/matters/{$matterId}/documents/{$docId}/move", [
            'matter_id' => $matterBId,
        ])->assertOk()->assertJsonPath('data.folder_id', null);

        $moved = $doc->fresh();
        $this->assertSame($matterBId, (string) $moved->matter_id);
        $this->assertNull($moved->folder_id);
        $this->assertSame(3, $moved->versions()->count());
        $this->assertSame(
            [1, 2, 3],
            $moved->versions()->orderBy('version_number')->pluck('version_number')->all()
        );
        // Audit history is never rewritten: the original move row still
        // points at matter A, the matter_moved row at matter B.
        $this->assertAuditHas('document.moved', ['document_id' => $docId], $matterId);
        $this->assertAuditHas('document.matter_moved', ['document_id' => $docId, 'to_matter_id' => $matterBId], $matterBId);

        // 3. Soft delete → trash.
        $this->deleteJson("/matters/{$matterBId}/documents/{$docId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'trash');

        $trashed = Document::query()->withTrashed()->whereKey($docId)->firstOrFail();
        $this->assertTrue($trashed->trashed());
        $this->assertAuditHas('document.trashed', ['document_id' => $docId]);

        // The trashed document is gone from the live list but visible in
        // the per-matter trash.
        $this->getJson("/matters/{$matterBId}/documents")
            ->assertOk()
            ->assertJsonMissing(['id' => $docId]);
        $this->getJson("/matters/{$matterBId}/documents/trash")
            ->assertOk()
            ->assertJsonFragment(['id' => $docId]);

        // The trash HTML view renders (phone-first, x-ui.* only).
        $trashHtml = $this->get("/matters/{$matterBId}/documents/trash")->assertOk()->getContent();
        $this->assertStringContainsString('Trash', $trashHtml);

        // 4. Restore → all versions back, status recomputed.
        $this->postJson("/matters/{$matterBId}/documents/{$docId}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $restored = Document::query()->whereKey($docId)->firstOrFail();
        $this->assertFalse($restored->trashed());
        $this->assertSame(3, $restored->versions()->count());
        $this->assertSame(
            [1, 2, 3],
            $restored->versions()->orderBy('version_number')->pluck('version_number')->all()
        );
        $this->assertAuditHas('document.restored', ['document_id' => $docId]);

        // 5. Legal hold blocks hard delete with 423 — the row survives.
        LegalHold::query()->create([
            'org_id' => $this->matterB->org_id,
            'matter_id' => $matterBId,
            'document_id' => $docId,
            'reason' => 'Litigation hold — synthetic fixture.',
            'created_by' => $this->admin->getKey(),
        ]);

        $this->deleteJson("/matters/{$matterBId}/documents/{$docId}/permanent")
            ->assertStatus(423)
            ->assertJsonPath('code', 'hold_locked');

        $this->assertNotNull(Document::query()->whereKey($docId)->first());
        $this->assertSame(3, DocumentVersion::query()->where('document_id', $docId)->count());
        $this->assertAuditHas('document.destroy.denied', ['document_id' => $docId]);

        // The scheduled purge skips held documents too. The canonical
        // 'Old engagement letter' is already trashed — age it past the
        // 30-day window and hold it (document-scoped, so it blocks only
        // itself).
        $held = Document::query()->withTrashed()
            ->where('matter_id', $this->matterA->getKey())
            ->where('title', 'Old engagement letter')
            ->firstOrFail();
        $heldId = (string) $held->getKey();
        LegalHold::query()->create([
            'org_id' => $this->matterA->org_id,
            'matter_id' => null,
            'document_id' => $heldId,
            'reason' => 'Litigation hold — synthetic fixture.',
            'created_by' => $this->admin->getKey(),
        ]);
        Document::query()->withTrashed()->whereKey($heldId)
            ->update(['deleted_at' => now()->subDays(31)]);

        Artisan::call('documents:purge-trash');
        $this->assertNotNull(Document::query()->withTrashed()->whereKey($heldId)->first());

        // 6. Release the hold → hard delete succeeds; versions and
        // orphaned blob bytes are gone; the audit row remains.
        LegalHold::query()->where('document_id', $docId)
            ->update(['released_at' => now(), 'released_by' => $this->admin->getKey(), 'release_reason' => 'Test release.']);

        $blobIds = DocumentVersion::query()->where('document_id', $docId)->pluck('blob_id')->unique()->all();

        $this->deleteJson("/matters/{$matterBId}/documents/{$docId}/permanent")->assertOk();

        $this->assertNull(Document::query()->withTrashed()->whereKey($docId)->first());
        $this->assertSame(0, DocumentVersion::query()->where('document_id', $docId)->count());
        foreach ($blobIds as $blobId) {
            $this->assertNull(DocumentBlob::query()->whereKey($blobId)->first());
        }
        $this->assertAuditHas('document.destroyed', ['document_id' => $docId]);

        // 7. Ungranted user: 404, and the title never leaks in the body.
        $probeDoc = $this->makeDocument($this->matterA, (string) $this->pleadings->getKey(), 'Leak sentinel probe', [], 1);
        $this->actingAs($this->noGrant);
        $probe = $this->postJson("/matters/{$matterId}/documents/{$probeDoc->getKey()}/move", [
            'folder_id' => (string) $this->discovery->getKey(),
        ]);
        $probe->assertNotFound();
        $this->assertStringNotContainsString('Leak sentinel probe', $probe->getContent());
    }

    public function test_folder_crud_and_empty_only_delete(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matterA->getKey();

        // Create.
        $folderId = $this->postJson("/matters/{$matterId}/folders", ['name' => 'Exhibits'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Exhibits')
            ->json('data.id');

        // Rename.
        $this->patchJson("/matters/{$matterId}/folders/{$folderId}", ['name' => 'Trial Exhibits'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Trial Exhibits');

        // Cycle guard: a folder cannot move under itself.
        $this->patchJson("/matters/{$matterId}/folders/{$folderId}", ['parent_id' => $folderId])
            ->assertStatus(422)
            ->assertJsonPath('code', 'folder_cycle');

        // Re-parent under Pleadings.
        $this->patchJson("/matters/{$matterId}/folders/{$folderId}", [
            'parent_id' => (string) $this->pleadings->getKey(),
        ])->assertOk()->assertJsonPath('data.parent_id', (string) $this->pleadings->getKey());

        // Non-empty folder cannot be deleted.
        $doc = $this->makeDocument($this->matterA, $folderId, 'Exhibit A', [], 1);
        $this->deleteJson("/matters/{$matterId}/folders/{$folderId}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'folder_not_empty');

        // Empty it via document move, then delete succeeds.
        $this->postJson("/matters/{$matterId}/documents/{$doc->getKey()}/move", [
            'folder_id' => (string) $this->discovery->getKey(),
        ])->assertOk();
        $this->deleteJson("/matters/{$matterId}/folders/{$folderId}")->assertOk();
        $this->assertNull(DocumentFolder::query()->whereKey($folderId)->first());

        // Folder from another matter reads as missing (leak-safe).
        $foreign = DocumentFolder::query()
            ->where('matter_id', $this->matterB->getKey())
            ->first();
        if ($foreign === null) {
            $foreign = DocumentFolder::query()->create([
                'org_id' => $this->matterB->org_id,
                'matter_id' => $this->matterB->getKey(),
                'name' => 'Foreign',
            ]);
        }
        $doc2 = $this->makeDocument($this->matterA, (string) $this->pleadings->getKey(), 'Foreign folder probe', [], 1);
        $this->postJson("/matters/{$matterId}/documents/{$doc2->getKey()}/move", [
            'folder_id' => (string) $foreign->getKey(),
        ])->assertStatus(422)->assertJsonPath('code', 'folder_not_in_matter');
    }

    public function test_move_matter_denied_without_destination_edit(): void
    {
        // The attorney has :edit on matter A but nothing on matter B —
        // the move must fail closed with document.access.denied audited.
        $attorney = $this->loader->docUser('a.attorney@sterling.example');
        MatterService::assignUser(
            $this->matterA,
            (string) $attorney->getKey(),
            'editor',
            null,
            $this->admin
        );

        $doc = Document::query()
            ->where('matter_id', $this->matterA->getKey())
            ->where('title', 'Fee agreement (executed)')
            ->firstOrFail();
        $matterId = (string) $this->matterA->getKey();
        $docId = (string) $doc->getKey();

        $this->actingAs($attorney);
        $this->postJson("/matters/{$matterId}/documents/{$docId}/move", [
            'matter_id' => (string) $this->matterB->getKey(),
        ])->assertNotFound(); // 404 — the destination must not leak

        $this->assertSame($matterId, (string) $doc->fresh()->matter_id);
        $this->assertAuditHas('document.access.denied', ['document_id' => $docId]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Assert an audit event was recorded whose payload contains the
     * expected subset (and optionally whose row points at a matter).
     * Payloads are filtered in PHP — jsonb operators in SQL are fiddly
     * and the cast already gives us arrays.
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertAuditHas(string $event, array $expected, ?string $rowMatterId = null): void
    {
        $events = AuditEvent::query()->where('event', $event)->get();

        foreach ($events as $auditEvent) {
            if ($rowMatterId !== null && (string) $auditEvent->matter_id !== $rowMatterId) {
                continue;
            }
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

    /**
     * Seed a document row directly through the T-01/T-02 model API for
     * rows the canonical fixtures don't cover.
     *
     * @param  list<string>  $tags
     */
    private function makeDocument(
        Matter $matter,
        ?string $folderId,
        string $title,
        array $tags,
        int $versions,
        string $kind = 'uploaded',
        string $status = 'ready',
        ?User $creator = null,
    ): Document {
        $creator ??= $this->admin;
        /** @var DocumentStore $store */
        $store = $this->app->make(DocumentStore::class);

        $doc = Document::query()->create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'folder_id' => $folderId,
            'title' => $title,
            'tags' => $tags,
            'kind' => $kind,
            'status' => $status,
            'created_by' => $creator->getKey(),
        ]);

        $lastVersion = null;
        for ($n = 1; $n <= $versions; $n++) {
            $blob = $store->put("synthetic bytes for {$title} v{$n}\n".str_repeat('x', 64));

            $lastVersion = DocumentVersion::query()->create([
                'document_id' => $doc->getKey(),
                'version_number' => $n,
                'blob_id' => $blob->getKey(),
                'processing_status' => 'ready',
                'page_count' => $n,
                'created_by' => $creator->getKey(),
            ]);
        }

        $doc->forceFill(['current_version_id' => $lastVersion->getKey()])->save();

        return $doc->refresh();
    }
}
