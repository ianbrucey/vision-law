<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\MatterGrant;
use App\Services\DocumentIngestService;
use App\Services\DocumentStore;
use App\Services\DocumentVersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Doubles\FakeOcrProvider;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-07 verdict: versioning — history, rollback, diff.
 *
 * test_version_lifecycle (C-10): v1→v2→v3; restore v1 → v4 with the old
 * bytes and the reason recorded; history never rewritten; diff v2/v3
 * shows added/removed lines; byte-identical upload → no new version +
 * notice.
 *
 * Contract edges covered alongside: restore without reason → 422
 * reason_required; restore as a viewer → 403 + the
 * document.version.restore.denied denial event; restoring the current
 * version → 200 no-op with notice.
 */
class DocumentVersioningTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

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

        $this->actingAs($this->loader->docUser('g.grant@sterling.example'));
    }

    // ------------------------------------------------------------------
    // C-10 — version lifecycle
    // ------------------------------------------------------------------

    public function test_version_lifecycle(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $actor = $this->loader->docUser('g.grant@sterling.example');
        $ingest = app(DocumentIngestService::class);

        $v1Bytes = "Complaint draft\nline two\nline three\n";
        $v2Bytes = "Complaint draft\nline two revised\nline three\nline four added\n";
        $v3Bytes = "Complaint draft\nline two revised\nline three changed\nline four added\n";

        // v1 through the upload pipeline.
        $document = $ingest->ingest($matter, $actor, $v1Bytes, [
            'title' => 'Versioned memo',
            'filename' => 'memo.txt',
            'mime' => 'text/plain',
        ]);

        // v2, v3 via "upload new version".
        $this->postUploadJson(
            route('documents.versions.store', [$matter->getKey(), $document->getKey()]),
            $this->uploadPayload('memo.txt', $v2Bytes, 'Revised line two, added line four')
        )->assertCreated()->assertJsonPath('data.version_number', 2);

        $this->postUploadJson(
            route('documents.versions.store', [$matter->getKey(), $document->getKey()]),
            $this->uploadPayload('memo.txt', $v3Bytes, 'Changed line three')
        )->assertCreated()->assertJsonPath('data.version_number', 3);

        $document->refresh();

        $this->assertSame(
            [1, 2, 3],
            $document->versions()->orderBy('version_number')->pluck('version_number')->all()
        );

        // Snapshot the immutable history before the rollback.
        $before = $document->versions()->orderBy('version_number')
            ->get(['id', 'version_number', 'blob_id', 'created_at', 'change_note'])
            ->keyBy('version_number');

        // Restore v1 → v4 with the old bytes and the reason recorded.
        $v1 = $document->versions()->where('version_number', 1)->firstOrFail();

        $this->postJson(
            route('documents.versions.restore', [$matter->getKey(), $document->getKey(), $v1->getKey()]),
            ['reason' => 'Reverting the erroneous revision']
        )->assertOk()->assertJsonPath('notice', 'version_restored');

        $document->refresh();

        $this->assertSame(
            [1, 2, 3, 4],
            $document->versions()->orderBy('version_number')->pluck('version_number')->all()
        );

        $v4 = $document->currentVersion()->firstOrFail();

        $this->assertSame(4, (int) $v4->version_number);
        $this->assertSame($v1->getKey(), $v4->restored_from_version_id);
        $this->assertStringContainsString('Reverting the erroneous revision', (string) $v4->change_note);

        // v4 holds v1's exact bytes (content-addressed: same blob row,
        // refcount incremented).
        $store = app(DocumentStore::class);
        $this->assertSame($v1Bytes, (string) $store->get($v4->blob()->firstOrFail()));
        $this->assertSame($v1->blob()->firstOrFail()->getKey(), $v4->blob()->firstOrFail()->getKey());

        // History was never rewritten: v1..v3 rows are untouched.
        $after = $document->versions()->orderBy('version_number')
            ->get(['id', 'version_number', 'blob_id', 'created_at', 'change_note'])
            ->keyBy('version_number');

        foreach ([1, 2, 3] as $n) {
            $this->assertSame($before[$n]->getKey(), $after[$n]->getKey());
            $this->assertSame($before[$n]->blob_id, $after[$n]->blob_id);
            $this->assertSame(
                $before[$n]->created_at->toIso8601String(),
                $after[$n]->created_at->toIso8601String()
            );
            $this->assertSame($before[$n]->change_note, $after[$n]->change_note);
        }

        // Structured diff v2 → v3 shows the added/removed lines.
        $versioning = app(DocumentVersioningService::class);
        $v2 = $document->versions()->where('version_number', 2)->firstOrFail();
        $v3 = $document->versions()->where('version_number', 3)->firstOrFail();
        $diff = $versioning->diff($v2, $v3);

        $this->assertTrue($diff['available']);
        $this->assertSame(2, $diff['from']);
        $this->assertSame(3, $diff['to']);

        $opTexts = ['add' => [], 'del' => []];
        foreach ($diff['pages'] as $page) {
            $this->assertSame(1, $page['page']); // page hint present
            foreach ($page['hunks'] as $hunk) {
                foreach ($hunk as $op) {
                    if ($op['type'] === 'add' || $op['type'] === 'del') {
                        $opTexts[$op['type']][] = trim($op['text']);
                    }
                }
            }
        }

        $this->assertContains('line three changed', $opTexts['add']);
        $this->assertContains('line three', $opTexts['del']);
        $this->assertSame(1, $diff['stats']['added']);
        $this->assertSame(1, $diff['stats']['removed']);

        // Byte-identical upload → no new version + notice.
        $versionCount = $document->versions()->count();

        $this->postUploadJson(
            route('documents.versions.store', [$matter->getKey(), $document->getKey()]),
            $this->uploadPayload('memo.txt', $v1Bytes, 'duplicate attempt')
        )->assertOk()->assertJsonPath('notice', 'identical_bytes_already_stored');

        $this->assertSame($versionCount, $document->versions()->count());

        // Restoring the current version is a 200 no-op with a notice.
        $current = $document->currentVersion()->firstOrFail();

        $this->postJson(
            route('documents.versions.restore', [$matter->getKey(), $document->getKey(), $current->getKey()]),
            ['reason' => 'already current']
        )->assertOk()->assertJsonPath('notice', 'already_current_version');

        $this->assertSame($versionCount, $document->versions()->count());

        // Restore without a reason → 422 reason_required.
        $this->postJson(
            route('documents.versions.restore', [$matter->getKey(), $document->getKey(), $v2->getKey()]),
            ['reason' => '   ']
        )->assertStatus(422)->assertJsonPath('code', 'reason_required');

        // Audit trail: versions created + the restore.
        $this->assertSame(
            3,
            AuditEvent::where('event', 'document.version.created')
                ->whereJsonContains('payload->document_id', (string) $document->getKey())
                ->count()
        );
        $this->assertDatabaseHas('audit_events', ['event' => 'document.version.restored']);
    }

    public function test_version_restore_denied_for_viewer(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $actor = $this->loader->docUser('g.grant@sterling.example');

        $document = app(DocumentIngestService::class)->ingest($matter, $actor, "alpha\n", [
            'title' => 'Denied restore memo',
            'filename' => 'denied.txt',
            'mime' => 'text/plain',
        ]);

        $viewer = $this->loader->docUser('v.viewer@sterling.example');

        // Viewer grant on the matter: :view yes, :edit no.
        MatterGrant::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => 'viewer',
            'granted_by' => $actor->getKey(),
        ]);

        $this->actingAs($viewer);

        $this->postJson(
            route('documents.versions.restore', [$matter->getKey(), $document->getKey(), $document->currentVersion()->firstOrFail()->getKey()]),
            ['reason' => 'viewer attempt']
        )->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('audit_events', ['event' => 'document.version.restore.denied']);

        // The viewer CAN read the history (matter.access:view).
        $this->get(route('documents.versions.index', [$matter->getKey(), $document->getKey()]))->assertOk();
    }

    public function test_version_history_view_renders(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $actor = $this->loader->docUser('g.grant@sterling.example');

        $document = app(DocumentIngestService::class)->ingest($matter, $actor, "one\ntwo\n", [
            'title' => 'History view memo',
            'filename' => 'history.txt',
            'mime' => 'text/plain',
        ]);

        $response = $this->get(route('documents.versions.index', [$matter->getKey(), $document->getKey()]));

        $response->assertOk();
        $response->assertSee('v1');
        $response->assertSee('Current');
        $response->assertSee('Upload new version');
    }

    public function test_version_diff_unavailable_for_image_without_text(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $actor = $this->loader->docUser('g.grant@sterling.example');

        // Minimal 1x1 PNG: image-only, no extracted text rows (T-06 not
        // run) → clean "diff unavailable" state, never OCR here.
        // Hermetic: the OCR fake returns empty text for this ingest so no
        // text rows exist even if the OCR job runs inline.
        FakeOcrProvider::$withText = false;
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        assert(is_string($png));

        $document = app(DocumentIngestService::class)->ingest($matter, $actor, $png, [
            'title' => 'Scan image',
            'filename' => 'scan.png',
            'mime' => 'image/png',
        ]);

        $v1 = $document->currentVersion()->firstOrFail();
        $diff = app(DocumentVersioningService::class)->diff($v1, $v1);

        $this->assertFalse($diff['available']);
        $this->assertSame('no_text', $diff['reason']);

        $response = $this->get(route('documents.versions.diff', [
            $matter->getKey(), $document->getKey(), 1, 1,
        ]));

        $response->assertOk();
        $response->assertSee('Diff unavailable');
    }

    /**
     * Multipart upload POST that expects a JSON response (the versions
     * endpoints serve JSON when the client accepts it, and redirect
     * otherwise — the Blade form path).
     *
     * @return array{file: UploadedFile, change_note: string}
     */
    private function uploadPayload(string $filename, string $bytes, string $note): array
    {
        return [
            'file' => UploadedFile::fake()->createWithContent($filename, $bytes),
            'change_note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postUploadJson(string $url, array $payload): TestResponse
    {
        return $this->post($url, $payload, ['Accept' => 'application/json']);
    }
}
