<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentVersion;
use App\Services\DocumentStore;
use App\Services\DocumentStoreException;
use App\Services\LocalDocumentStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-01 verdicts: storage foundation + schema.
 *
 * - test_document_store_dedupe: identical bytes → one blob row, refcount 2.
 * - test_migrations_rollback: all 12 T-01 migrations roll back cleanly.
 * - test_versions_immutable: direct UPDATE/DELETE on document_versions →
 *   DB error (BEFORE trigger, 007-D06).
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 12 T-01 tables, in migration order.
     *
     * @var list<string>
     */
    private const T01_TABLES = [
        'document_blobs',
        'document_folders',
        'documents',
        'document_versions',
        'upload_sessions',
        'document_templates',
        'document_shares',
        'document_grants',
        'retention_policies',
        'legal_holds',
        'disposition_queue',
        'document_tombstones',
    ];

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
    }

    public function test_document_store_dedupe(): void
    {
        $store = $this->app->make(DocumentStore::class);
        $this->assertInstanceOf(LocalDocumentStore::class, $store);

        $bytes = "synthetic complaint bytes\n".str_repeat('x', 1024);

        $first = $store->put($bytes);
        $second = $store->put($bytes);

        // Identical bytes → one blob row, refcount 2.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DocumentBlob::count());
        $this->assertSame(2, (int) $second->fresh()->refcount);

        // Distinct bytes → distinct blob row.
        $third = $store->put($bytes.'different');
        $this->assertSame(2, DocumentBlob::count());
        $this->assertNotSame($first->id, $third->id);

        // Round-trip: exact bytes back.
        $this->assertSame($bytes, (string) $store->get($first));

        // MIME is sniffed from the bytes, never trusted from an extension.
        $this->assertSame('text/plain', $first->mime_sniffed);

        // Refcount guard: delete with live references throws.
        try {
            $store->delete($first);
            $this->fail('expected delete with refcount > 0 to throw');
        } catch (DocumentStoreException $e) {
            $this->assertStringContainsString('refcount', $e->getMessage());
        }

        // refcount 0 → bytes and row are removed.
        $first->forceFill(['refcount' => 0])->save();
        $path = $first->storage_path;
        $store->delete($first->fresh());
        $this->assertFalse(Storage::disk('documents')->exists($path));
        $this->assertSame(1, DocumentBlob::count());
    }

    public function test_quarantine_moves_bytes_out_of_reach(): void
    {
        $store = $this->app->make(DocumentStore::class);

        $blob = $store->put('suspicious bytes', ['quarantined' => true]);

        $this->assertTrue($blob->quarantined);
        $this->assertStringStartsWith(
            trim((string) config('document.quarantine_prefix'), '/').'/',
            $blob->storage_path
        );

        // Quarantined bytes are never served back.
        try {
            $store->get($blob);
            $this->fail('expected get on quarantined blob to throw');
        } catch (DocumentStoreException $e) {
            $this->assertStringContainsString('quarantined', $e->getMessage());
        }
    }

    public function test_migrations_rollback(): void
    {
        foreach (self::T01_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "expected table {$table} to exist after migrate");
        }

        Artisan::call('migrate:rollback', ['--step' => 12]);

        foreach (self::T01_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "expected table {$table} to be gone after rollback");
        }

        // Re-migrate so the suite (and this test's teardown) sees a whole DB.
        Artisan::call('migrate');

        foreach (self::T01_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "expected table {$table} to exist after re-migrate");
        }
    }

    public function test_versions_immutable(): void
    {
        $fixtures = FixtureLoader::loadDocumentFixtures();
        $org = $fixtures->docOrg('sterling');
        $matter = $fixtures->docMatter('MAT-2026-001');
        $user = $fixtures->docUser('g.grant@sterling.example');

        $blob = DocumentBlob::create([
            'sha256' => hash('sha256', 'version bytes'),
            'size' => 13,
            'mime_sniffed' => 'text/plain',
            'storage_path' => 'test/placeholder',
            'refcount' => 1,
        ]);

        $document = Document::create([
            'org_id' => $org->id,
            'matter_id' => $matter->id,
            'title' => 'Immutable test document',
            'kind' => 'uploaded',
            'status' => 'ready',
            'created_by' => $user->id,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'blob_id' => $blob->id,
            'processing_status' => 'ready',
            'created_by' => $user->id,
        ]);

        // Direct UPDATE → DB error (BEFORE UPDATE trigger, 007-D06).
        // Each attempt runs in its own savepoint: Postgres aborts the
        // enclosing transaction on the first error, so a bare try/catch
        // would poison the second attempt.
        try {
            DB::transaction(fn () => DB::table('document_versions')
                ->where('id', $version->id)
                ->update(['change_note' => 'rewriting history']));
            $this->fail('expected UPDATE on document_versions to raise');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Direct DELETE → DB error too.
        try {
            DB::transaction(fn () => DB::table('document_versions')
                ->where('id', $version->id)
                ->delete());
            $this->fail('expected DELETE on document_versions to raise');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // The row is untouched.
        $this->assertSame(1, DocumentVersion::count());
        $this->assertNull($version->fresh()->change_note);
    }
}
