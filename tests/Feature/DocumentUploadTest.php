<?php

namespace Tests\Feature;

use App\Mail\DocumentQuarantinedMail;
use App\Mail\ScanStalledAlertMail;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Services\DocumentStore;
use App\Services\DocumentStoreException;
use App\Services\MalwareScanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-02 verdicts: upload pipeline + malware scan.
 *
 * - test_single_upload (C-01): 5 MiB PDF → 201, blob stored once;
 *   re-upload identical → same blob, no new row; 101 MiB → 413, nothing
 *   stored.
 * - test_chunked_upload_resume (C-02): 200 MiB in chunks, mid-way drop,
 *   resume via bitmap → completes; wrong final hash → 422, chunks
 *   retained; cancel → chunks deleted.
 * - test_malware_scan (C-03): EICAR → quarantined, bytes unreachable;
 *   engine stopped → upload stays `scanning` after 5 min, alert queued.
 */
class DocumentUploadTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // C-01 — single-shot upload
    // ------------------------------------------------------------------

    public function test_single_upload(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');

        $blobCount = DocumentBlob::count();
        $docCount = Document::count();

        // 5 MiB PDF.
        $bytes = $this->pdfBytes(5 * 1024 * 1024);
        $response = $this->post(
            "/matters/{$matter->getKey()}/documents/upload",
            ['file' => $this->uploadedFile($bytes, 'complaint.pdf'), 'title' => 'Complaint'],
            ['Accept' => 'application/json']
        );

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('ready', $data['status']);
        $this->assertSame('ready', $data['version']['processing_status']);
        $this->assertSame(5 * 1024 * 1024, $data['version']['size']);

        // MIME sniffed from the bytes — never trusted from the extension.
        $firstBlobId = DocumentBlob::where('sha256', hash('sha256', $bytes))->value('id');
        $this->assertNotNull($firstBlobId);
        $this->assertSame('application/pdf', DocumentBlob::findOrFail($firstBlobId)->mime_sniffed);
        $this->assertSame($blobCount + 1, DocumentBlob::count());
        $this->assertSame($docCount + 1, Document::count());

        // Misleading extension: PDF bytes named .txt still sniff as PDF.
        $evil = $this->post(
            "/matters/{$matter->getKey()}/documents/upload",
            ['file' => $this->uploadedFile($this->pdfBytes(1024), 'evil.txt')],
            ['Accept' => 'application/json']
        );
        $evil->assertCreated();
        $this->assertSame('application/pdf', $evil->json('data.version.mime'));

        // Re-upload identical bytes → same blob, no new row, notice.
        $reupload = $this->post(
            "/matters/{$matter->getKey()}/documents/upload",
            ['file' => $this->uploadedFile($bytes, 'complaint-copy.pdf')],
            ['Accept' => 'application/json']
        );

        $reupload->assertOk();
        $this->assertSame('identical_bytes_already_stored', $reupload->json('notice'));
        $this->assertSame($data['id'], $reupload->json('data.id'));
        // Blob stored once: still exactly one blob row for these bytes.
        $this->assertSame($firstBlobId, DocumentBlob::where('sha256', hash('sha256', $bytes))->value('id'));

        // 101 MiB → 413 before storage: nothing stored.
        $blobCountBefore = DocumentBlob::count();
        $docCountBefore = Document::count();

        $bigPath = tempnam(sys_get_temp_dir(), 'vl-big-');
        $handle = fopen($bigPath === false ? '/dev/null' : $bigPath, 'wb');
        assert($handle !== false);
        $oneMb = str_repeat('0', 1024 * 1024);
        for ($i = 0; $i < 101; $i++) {
            fwrite($handle, $oneMb);
        }
        fclose($handle);
        assert($bigPath !== false);

        $big = new UploadedFile($bigPath, 'too-big.pdf', 'application/pdf', null, true);

        $tooBig = $this->post(
            "/matters/{$matter->getKey()}/documents/upload",
            ['file' => $big],
            ['Accept' => 'application/json']
        );

        $tooBig->assertStatus(413);
        $tooBig->assertJson(['code' => 'upload_too_large']);
        $this->assertSame($blobCountBefore, DocumentBlob::count());
        $this->assertSame($docCountBefore, Document::count());

        unlink($bigPath);

        // Upload audit written.
        $this->assertTrue($this->auditFor('document.uploaded', $data['id']));
    }

    // ------------------------------------------------------------------
    // C-02 — chunked/resumable upload
    // ------------------------------------------------------------------

    public function test_chunked_upload_resume(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $base = "/matters/{$matter->getKey()}/documents/uploads";

        $chunkSize = 8 * 1024 * 1024;
        $totalSize = 200 * 1024 * 1024;
        $totalChunks = 25;

        // Deterministic 200 MiB payload that sniffs as PDF (the upload
        // allowlist is enforced on sniffed bytes, C-04).
        $head = "%PDF-1.4\n";
        $data = $head.str_repeat(random_bytes(4096), (int) (($totalSize - strlen($head)) / 4096));
        $data .= str_repeat('0', $totalSize - strlen($data));
        $this->assertSame($totalSize, strlen($data));
        $digest = hash('sha256', $data);

        // Initiate.
        $init = $this->postJson("{$base}/init", [
            'filename' => 'binder.pdf',
            'size' => $totalSize,
            'sha256' => $digest,
            'title' => 'Big binder',
        ]);

        $init->assertCreated();
        $sessionId = $init->json('data.id');
        $this->assertSame($chunkSize, $init->json('data.chunk_size'));
        $this->assertSame($totalChunks, $init->json('data.total_chunks'));
        $this->assertSame([], $init->json('data.received_chunks'));

        $chunkUrl = static fn (int $n): string => "{$base}/{$sessionId}/chunks/{$n}";

        // Upload the first 10 chunks, then "drop the connection".
        for ($i = 0; $i < 10; $i++) {
            $this->putChunk($chunkUrl($i), substr($data, $i * $chunkSize, $chunkSize))->assertOk();
        }

        // Resume: the bitmap shows exactly what arrived.
        $show = $this->getJson("{$base}/{$sessionId}");
        $show->assertOk();
        $this->assertSame(range(0, 9), $show->json('data.received_chunks'));

        // Idempotent re-PUT of identical bytes → 200 no-op.
        $again = $this->putChunk($chunkUrl(3), substr($data, 3 * $chunkSize, $chunkSize));
        $again->assertOk();
        $this->assertTrue($again->json('data.deduped'));

        // Re-PUT of differing bytes → 409 conflict.
        $conflict = $this->putChunk($chunkUrl(3), str_repeat('X', $chunkSize), ['Accept' => 'application/json']);
        $conflict->assertStatus(409);
        $conflict->assertJson(['code' => 'chunk_conflict']);

        // Resume the rest.
        for ($i = 10; $i < $totalChunks; $i++) {
            $this->putChunk($chunkUrl($i), substr($data, $i * $chunkSize, $chunkSize))->assertOk();
        }

        // Complete: total SHA-256 verified, document ingested.
        $complete = $this->postJson("{$base}/{$sessionId}/complete", []);
        $complete->assertCreated();

        $doc = $complete->json('data');
        $this->assertSame('ready', $doc['status']);
        $this->assertSame($totalSize, $doc['version']['size']);

        $blob = DocumentBlob::where('sha256', $digest)->firstOrFail();
        $this->assertSame($totalSize, (int) $blob->size);

        // Wrong final hash → 422, session and chunks retained.
        $badInit = $this->postJson("{$base}/init", [
            'filename' => 'binder.pdf',
            'size' => $totalSize,
            'sha256' => str_repeat('0', 64),
        ]);
        $badInit->assertCreated();
        $badId = $badInit->json('data.id');

        for ($i = 0; $i < $totalChunks; $i++) {
            $this->putChunk("{$base}/{$badId}/chunks/{$i}", substr($data, $i * $chunkSize, $chunkSize))->assertOk();
        }

        $badComplete = $this->postJson("{$base}/{$badId}/complete", [], ['Accept' => 'application/json']);
        $badComplete->assertStatus(422);
        $badComplete->assertJson(['code' => 'hash_mismatch']);

        $badShow = $this->getJson("{$base}/{$badId}");
        $badShow->assertOk();
        $this->assertSame('active', $badShow->json('data.status'));
        $this->assertCount($totalChunks, $badShow->json('data.received_chunks'));

        // Cancel → chunks deleted.
        $cancelInit = $this->postJson("{$base}/init", [
            'filename' => 'abandoned.pdf',
            'size' => $totalSize,
            'sha256' => $digest,
        ]);
        $cancelId = $cancelInit->json('data.id');

        $this->putChunk("{$base}/{$cancelId}/chunks/0", substr($data, 0, $chunkSize))->assertOk();
        $this->putChunk("{$base}/{$cancelId}/chunks/1", substr($data, $chunkSize, $chunkSize))->assertOk();

        $cancel = $this->deleteJson("{$base}/{$cancelId}");
        $cancel->assertOk();

        $this->assertFalse(Storage::disk('documents')->exists("staging/{$cancelId}"));
        $this->assertSame('cancelled', $this->getJson("{$base}/{$cancelId}")->json('data.status'));
    }

    // ------------------------------------------------------------------
    // C-03 — malware scan
    // ------------------------------------------------------------------

    public function test_malware_scan(): void
    {
        Mail::fake();

        // Fresh matter: the fixture loader already quarantined EICAR bytes in
        // MAT-2026-001, and same-matter identical bytes dedupe to a 200 no-op.
        $matter = $this->loader->docMatter('MAT-2026-002');
        $url = "/matters/{$matter->getKey()}/documents/upload";

        // EICAR test file → quarantined.
        $infected = $this->post(
            $url,
            ['file' => $this->uploadedFile(MalwareScanner::EICAR_TEST_STRING, 'invoice.exe.pdf'), 'title' => 'Suspicious attachment'],
            ['Accept' => 'application/json']
        );

        $infected->assertCreated();
        $docId = $infected->json('data.id');
        $this->assertSame('quarantined', $infected->json('data.status'));
        $this->assertSame('failed', $infected->json('data.version.processing_status'));

        $document = Document::findOrFail($docId);
        $blob = $document->currentVersion->blob;
        $this->assertTrue((bool) $blob->quarantined);

        // Bytes are unreachable: the store refuses quarantined blobs. (T-03's
        // preview/download controllers gate on the `quarantined` document
        // status → 403 per the contract's error catalog.)
        try {
            app(DocumentStore::class)->get($blob);
            $this->fail('expected quarantined blob to be unservable');
        } catch (DocumentStoreException $e) {
            $this->assertStringContainsString('quarantined', $e->getMessage());
        }

        $this->assertTrue($this->auditFor('document.quarantined', $docId));

        // Admin notification queued to an org admin.
        Mail::assertQueued(DocumentQuarantinedMail::class, function (DocumentQuarantinedMail $mail): bool {
            return $mail->hasTo('g.grant@sterling.example');
        });

        // Engine unreachable → upload stays `scanning`, never silently clean.
        Config::set('document.clamav.socket', '/nonexistent/clamd-test.ctl');
        Config::set('document.ops_alert_email', 'ops@example.test');

        $pending = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->pdfBytes(2048), 'clean.pdf'), 'title' => 'Clean doc'],
            ['Accept' => 'application/json']
        );

        $pending->assertCreated();
        $pendingId = $pending->json('data.id');
        $this->assertSame('processing', $pending->json('data.status'));
        $this->assertSame('scanning', $pending->json('data.version.processing_status'));

        // 5 minutes pass with no scan result → the sweeper queues the ops
        // alert; the document is still `scanning` (never marked clean).
        Carbon::setTestNow(now()->addMinutes(6));

        Artisan::call('documents:sweep-stale-scans');

        $this->assertSame('processing', Document::findOrFail($pendingId)->status);
        $this->assertSame(
            'scanning',
            Document::findOrFail($pendingId)->currentVersion->processing_status
        );

        Mail::assertQueued(ScanStalledAlertMail::class, function (ScanStalledAlertMail $mail): bool {
            return $mail->hasTo('ops@example.test');
        });
        $this->assertTrue($this->auditFor('document.scan.delayed', $pendingId));

        // The alert fires exactly once — a second sweep does not re-queue.
        Artisan::call('documents:sweep-stale-scans');
        $this->assertCount(1, Mail::queued(ScanStalledAlertMail::class));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function pdfBytes(int $size): string
    {
        $head = "%PDF-1.4\n%synthetic upload fixture\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        $padLength = max(0, $size - strlen($head));

        return $head.str_repeat('q', $padLength);
    }

    private function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-test-');
        assert($path !== false);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Raw-body PUT for chunk uploads.
     */
    private function putChunk(string $url, string $bytes, array $headers = []): TestResponse
    {
        return $this->call(
            'PUT',
            $url,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'] + $headers,
            $bytes
        );
    }

    private function auditFor(string $event, string $documentId): bool
    {
        return AuditEvent::where('event', $event)->get()->contains(
            static fn (AuditEvent $audit): bool => ($audit->payload['document_id'] ?? null) === $documentId
        );
    }
}
