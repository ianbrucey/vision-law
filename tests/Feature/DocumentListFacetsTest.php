<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentSavedView;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentFilingService;
use App\Services\DocumentStore;
use App\Services\MatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-05 verdict: C-09 list half.
 *
 * Named verdict: test_document_list_facets — faceted filters, keyword
 * search, saved views, 50/page pagination, permission-scoped (ungranted
 * user sees nothing — leak sentinel).
 *
 * The corpus is the canonical 007 fixtures (loaded through T-02's
 * ingest pipeline) plus T05-prefixed rows for facets the fixtures
 * don't cover (authored kind, retention-flagged, description search,
 * 4-version documents).
 */
class DocumentListFacetsTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    private Matter $matter;

    private User $admin;

    private User $attorney;

    private User $noGrant;

    private DocumentFolder $pleadings;

    private DocumentFolder $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/visionlaw-test-documents',
            'throw' => true,
            'report' => false,
        ]);
        Config::set('document.disk', 'documents');
        Storage::forgetDisk('documents');

        $this->loader = FixtureLoader::loadDocumentFixtures();
        $this->matter = $this->loader->docMatter('MAT-2026-001');
        $this->admin = $this->loader->docUser('g.grant@sterling.example');
        $this->attorney = $this->loader->docUser('a.attorney@sterling.example');
        $this->noGrant = $this->loader->docUser('nogrant@sterling.example');

        $this->pleadings = DocumentFolder::query()
            ->where('matter_id', $this->matter->getKey())
            ->where('name', 'Pleadings')
            ->firstOrFail();
        $this->discovery = DocumentFolder::query()
            ->where('matter_id', $this->matter->getKey())
            ->where('name', 'Discovery')
            ->firstOrFail();

        $this->seedT05Corpus();
    }

    // ── C-09 list half ────────────────────────────────────────────────

    public function test_document_list_facets(): void
    {
        $this->actingAs($this->admin);
        $base = '/matters/'.(string) $this->matter->getKey().'/documents';

        // Kind facet: 'authored' covers only the T05 rows (the canonical
        // fixtures defer authored documents to T-04's pipeline).
        $authoredTitles = $this->getJson($base.'?kind=authored')
            ->assertOk()->json('data.*.title');
        $this->assertContains('T05 authored brief', $authoredTitles);
        $this->assertContains('T05 second authored', $authoredTitles);
        $this->assertNotContains('Complaint — filed stamp', $authoredTitles);

        // Folder facet: Pleadings + descendants.
        $pleadingTitles = $this->getJson($base.'?folder_id='.(string) $this->pleadings->getKey())
            ->assertOk()->json('data.*.title');
        $this->assertContains('Complaint — filed stamp', $pleadingTitles);
        $this->assertContains('T05 authored brief', $pleadingTitles);
        $this->assertContains('T05 multi-version exhibit', $pleadingTitles);
        $this->assertNotContains('Site photos', $pleadingTitles); // Discovery

        // Uploader facet: canonical fixtures were ingested by the
        // attorney; the T05 rows by the admin.
        $attorneyTitles = $this->getJson($base.'?uploader='.(string) $this->attorney->getKey())
            ->assertOk()->json('data.*.title');
        $this->assertContains('Complaint — filed stamp', $attorneyTitles);
        $this->assertContains('Site photos', $attorneyTitles);
        $this->assertNotContains('T05 authored brief', $attorneyTitles);

        $adminTitles = $this->getJson($base.'?uploader='.(string) $this->admin->getKey())
            ->assertOk()->json('data.*.title');
        $this->assertContains('T05 authored brief', $adminTitles);
        $this->assertNotContains('Site photos', $adminTitles);

        // Retention-flag facet.
        $flaggedTitles = $this->getJson($base.'?retention_flagged=1')
            ->assertOk()->json('data.*.title');
        $this->assertContains('T05 flagged memo', $flaggedTitles);
        $this->assertNotContains('Site photos', $flaggedTitles);

        // Date-range facet (canonical fixtures are ingested "now"; the
        // T05 flagged memo is back-dated into September).
        $dateTitles = $this->getJson($base.'?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()->json('data.*.title');
        $this->assertContains('T05 flagged memo', $dateTitles);
        $this->assertNotContains('Complaint — filed stamp', $dateTitles);
        $this->assertNotContains('T05 authored brief', $dateTitles);

        // Version-count facet.
        $multiTitles = $this->getJson($base.'?min_versions=2')
            ->assertOk()->json('data.*.title');
        $this->assertContains('Complaint — filed stamp', $multiTitles); // 3 versions
        $this->assertContains('T05 authored brief', $multiTitles);     // 2 versions
        $this->assertContains('T05 multi-version exhibit', $multiTitles); // 4 versions
        $this->assertNotContains('Site photos', $multiTitles);          // 1 version

        // Keyword search: title…
        $titleHits = $this->getJson($base.'?q=complaint')->assertOk()->json('data.*.title');
        $this->assertContains('Complaint — filed stamp', $titleHits);

        // …tag…
        $tagHits = $this->getJson($base.'?q=filed')->assertOk()->json('data.*.title');
        $this->assertContains('Complaint — filed stamp', $tagHits);

        // …description.
        $descHits = $this->getJson($base.'?q=t05 test brief')->assertOk()->json('data.*.title');
        $this->assertContains('T05 authored brief', $descHits);
        $this->assertNotContains('Site photos', $descHits);

        // Facet metadata rides along for the filter UI.
        $meta = $this->getJson($base)->assertOk()->json('facets');
        $this->assertArrayHasKey('kinds', $meta);
        $this->assertArrayHasKey('uploaders', $meta);
        $this->assertArrayHasKey('retention_flagged', $meta);
        $this->assertSame(1, $meta['retention_flagged']);
        $this->assertNotEmpty($meta['folders']);

        // The trashed fixture document never leaks into the live list.
        $liveTitles = $this->getJson($base)->assertOk()->json('data.*.title');
        $this->assertNotContains('Old engagement letter', $liveTitles);
    }

    public function test_document_list_saved_views(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matter->getKey();

        $viewId = $this->postJson("/matters/{$matterId}/document-views", [
            'name' => 'My authored docs',
            'filters' => ['kind' => 'authored'],
        ])->assertCreated()->assertJsonPath('data.name', 'My authored docs')->json('data.id');

        $this->assertTrue(
            DocumentSavedView::query()->whereKey($viewId)->where('user_id', $this->admin->getKey())->exists()
        );

        // Applying the saved view filters the list.
        $titles = $this->getJson("/matters/{$matterId}/documents?view_id={$viewId}")
            ->assertOk()->json('data.*.title');
        $this->assertContains('T05 authored brief', $titles);
        $this->assertNotContains('Site photos', $titles);

        // Another user's views are invisible (leak sentinel). The attorney
        // gets :view on the matter so they can reach the endpoint.
        MatterService::assignUser(
            $this->matter,
            (string) $this->attorney->getKey(),
            'viewer',
            null,
            $this->admin
        );
        $this->actingAs($this->attorney);
        $this->getJson("/matters/{$matterId}/document-views")
            ->assertOk()
            ->assertJsonMissing(['id' => $viewId]);

        $this->actingAs($this->admin);
        $this->deleteJson("/matters/{$matterId}/document-views/{$viewId}")->assertOk();
        $this->assertNull(DocumentSavedView::query()->whereKey($viewId)->first());
    }

    public function test_document_list_pagination(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matter->getKey();

        for ($i = 1; $i <= 55; $i++) {
            $this->makeDocument($this->matter, null, "T05 bulk doc {$i}", [], 1);
        }

        $total = Document::query()->where('matter_id', $this->matter->getKey())->count();
        $this->assertGreaterThan(50, $total);

        $page1 = $this->getJson("/matters/{$matterId}/documents")->assertOk();
        $page1->assertJsonPath('meta.total', $total)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', (int) ceil($total / 50))
            ->assertJsonCount(50, 'data');

        $page2 = $this->getJson("/matters/{$matterId}/documents?page=2")->assertOk();
        $page2->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount($total - 50, 'data');

        // Pages do not overlap.
        $ids1 = $page1->json('data.*.id');
        $ids2 = $page2->json('data.*.id');
        $this->assertEmpty(array_intersect($ids1, $ids2));

        // Keyword search results paginate too.
        $this->getJson("/matters/{$matterId}/documents?q=T05%20bulk")
            ->assertOk()
            ->assertJsonPath('meta.total', 55);
    }

    public function test_document_list_html_renders(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matter->getKey();

        $response = $this->get("/matters/{$matterId}/documents");
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Search documents', $html);
        $this->assertStringContainsString('Complaint — filed stamp', $html);
        $this->assertStringContainsString('Folders', $html);
        $this->assertStringContainsString('Bulk actions', $html);

        // Phone-first gate: no full-page horizontal scroll affordances —
        // tables scroll inside their cards.
        $this->assertStringNotContainsString('overflow-x-auto w-screen', $html);
    }

    public function test_document_list_permission_scoped(): void
    {
        // Leak sentinel: a viewer with no grant on this matter sees
        // nothing — 404, and no titles anywhere in the response.
        $this->actingAs($this->noGrant);
        $matterId = (string) $this->matter->getKey();

        $response = $this->getJson("/matters/{$matterId}/documents");
        $response->assertNotFound();
        $this->assertStringNotContainsString('Complaint — filed stamp', $response->getContent());
        $this->assertStringNotContainsString('T05 authored brief', $response->getContent());
    }

    public function test_bulk_move_tag_and_zip(): void
    {
        $this->actingAs($this->admin);
        $matterId = (string) $this->matter->getKey();

        $a = $this->makeDocument($this->matter, null, 'T05 bulk a', ['alpha'], 1);
        $b = $this->makeDocument($this->matter, null, 'T05 bulk b', ['beta'], 1);
        $ids = [(string) $a->getKey(), (string) $b->getKey()];

        // Bulk move.
        $this->postJson("/matters/{$matterId}/documents/bulk/move", [
            'document_ids' => $ids,
            'folder_id' => (string) $this->discovery->getKey(),
        ])->assertOk()->assertJsonPath('moved', 2);
        $this->assertSame((string) $this->discovery->getKey(), (string) $a->fresh()->folder_id);
        $this->assertSame((string) $this->discovery->getKey(), (string) $b->fresh()->folder_id);

        // Bulk tag (add).
        $this->postJson("/matters/{$matterId}/documents/bulk/tag", [
            'document_ids' => $ids,
            'tags' => 'urgent, exhibit-a',
            'mode' => 'add',
        ])->assertOk()->assertJsonPath('tagged', 2);
        $this->assertContains('urgent', $a->fresh()->tags);
        $this->assertContains('alpha', $a->fresh()->tags);

        // Bulk tag (remove).
        $this->postJson("/matters/{$matterId}/documents/bulk/tag", [
            'document_ids' => $ids,
            'tags' => 'alpha',
            'mode' => 'remove',
        ])->assertOk();
        $this->assertNotContains('alpha', $a->fresh()->tags);

        // Bulk ZIP download: assert transport-level behaviour over HTTP,
        // then inspect the archive bytes through the service (the HTTP
        // test response doesn't expose BinaryFileResponse bodies).
        $response = $this->postJson("/matters/{$matterId}/documents/bulk/download", [
            'document_ids' => $ids,
        ]);
        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('Content-Type'));

        /** @var DocumentFilingService $filing */
        $filing = $this->app->make(DocumentFilingService::class);
        $zipPath = DocumentFilingService::bulkDownloadZip($this->matter, $ids, $this->admin);
        $this->assertFileExists($zipPath);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($zipPath);

        // Folder structure is preserved inside the archive.
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'Discovery/')));
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'T05 bulk a')));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * T05-specific rows the canonical fixtures don't cover: authored
     * kind, retention-flagged, description search, multi-version docs.
     */
    private function seedT05Corpus(): void
    {
        $this->makeDocument(
            $this->matter,
            (string) $this->pleadings->getKey(),
            'T05 authored brief',
            ['t05'],
            2,
            'authored',
            '2026-10-06',
            $this->admin,
            'T05 test brief about summary judgment.'
        );

        $this->makeDocument(
            $this->matter,
            null,
            'T05 second authored',
            ['t05'],
            1,
            'authored',
            '2026-10-06',
            $this->admin
        );

        $flagged = $this->makeDocument(
            $this->matter,
            (string) $this->discovery->getKey(),
            'T05 flagged memo',
            ['t05'],
            1,
            'uploaded',
            '2026-09-15',
            $this->admin
        );
        $flagged->forceFill(['retention_flagged_at' => now()])->save();

        $this->makeDocument(
            $this->matter,
            (string) $this->pleadings->getKey(),
            'T05 multi-version exhibit',
            ['t05'],
            4,
            'uploaded',
            '2026-10-06',
            $this->admin
        );
    }

    /**
     * @param  list<string>  $tags
     */
    private function makeDocument(
        Matter $matter,
        ?string $folderId,
        string $title,
        array $tags,
        int $versions,
        string $kind = 'uploaded',
        string $createdAt = '2026-10-06',
        ?User $creator = null,
        ?string $description = null,
    ): Document {
        $creator ??= $this->admin;
        /** @var DocumentStore $store */
        $store = $this->app->make(DocumentStore::class);

        $doc = Document::query()->create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'folder_id' => $folderId,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'kind' => $kind,
            'status' => 'ready',
            'created_by' => $creator->getKey(),
        ]);

        // created_at is not mass-assignable — pin it (and updated_at) here.
        $doc->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

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
