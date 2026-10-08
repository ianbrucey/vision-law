<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-10 verdict: test_document_audit (C-14).
 *
 * Append-only document audit trail: upload → preview → download →
 * restore → share each writes EXACTLY ONE row for its cataloged event
 * (brief §Security classification). The per-document Activity tab
 * renders the chain; the matter-level CSV export contains it. A
 * permission-denied actor gets nothing from either surface.
 */
class DocumentAuditTest extends TestCase
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

    public function test_document_audit(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $m = (string) $matter->getKey();
        $viewer = $this->loader->docUser('v.viewer@sterling.example');

        $count = fn (string $event, string $docId): int => AuditEvent::query()
            ->where('event', $event)
            ->whereJsonContains('payload->document_id', $docId)
            ->count();

        // ── upload → exactly one document.uploaded ──
        $upload = $this->post(
            "/matters/{$m}/documents/upload",
            ['file' => $this->uploadedFile($this->pdfBytes('Audit chain'), 'audit-chain.pdf'), 'title' => 'Audit chain'],
            ['Accept' => 'application/json']
        );
        $upload->assertCreated();
        $docId = (string) $upload->json('data.id');
        $this->assertSame(1, $count('document.uploaded', $docId));

        // ── preview → exactly one document.previewed ──
        $page = $this->get("/matters/{$m}/documents/{$docId}/preview");
        $page->assertOk();
        $this->assertSame(1, $count('document.previewed', $docId));

        // ── download → exactly one document.downloaded ──
        $downloadUrl = $this->previewDownloadUrl($page->getContent());
        $this->assertNotNull($downloadUrl);
        $download = $this->get($downloadUrl);
        $download->assertOk();
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
        $this->assertSame(1, $count('document.downloaded', $docId));

        // ── restore → exactly one document.version.restored ──
        // Upload v2, then restore v1 (→ v3 with the old bytes + reason).
        $doc = Document::findOrFail($docId);
        $v1 = $doc->versions()->where('version_number', 1)->firstOrFail();
        $this->post(
            route('documents.versions.store', [$m, $docId]),
            ['file' => UploadedFile::fake()->createWithContent('audit-chain-v2.pdf', $this->pdfBytes('Audit chain v2')), 'change_note' => 'v2'],
            ['Accept' => 'application/json']
        )->assertCreated();
        $this->postJson(
            route('documents.versions.restore', [$m, $docId, $v1->getKey()]),
            ['reason' => 'audit chain restore check']
        )->assertOk();
        $this->assertSame(1, $count('document.version.restored', $docId));

        // ── share (internal grant) → exactly one document.shared ──
        $this->postJson(
            route('documents.grants.store', [$m, $docId]),
            ['user_id' => (string) $viewer->getKey(), 'level' => 'viewer']
        )->assertCreated();
        $this->assertSame(1, $count('document.shared', $docId));

        // ── per-document Activity tab renders the full chain ──
        $activity = $this->get(route('documents.activity', [$m, $docId]));
        $activity->assertOk();
        foreach (['document.uploaded', 'document.previewed', 'document.downloaded', 'document.version.restored', 'document.shared'] as $event) {
            $activity->assertSee($event, false);
        }
        // No token/hash material ever reaches the activity surface.
        $activity->assertDontSee('token_hash', false);
        $activity->assertDontSee('password_hash', false);

        // ── matter-level CSV export contains the full chain ──
        $csv = $this->get(route('documents.activity.export', [$m]));
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString('timestamp,event,actor', $body);
        foreach (['document.uploaded', 'document.previewed', 'document.downloaded', 'document.version.restored', 'document.shared'] as $event) {
            $this->assertStringContainsString($event, $body);
        }
        $this->assertStringContainsString($docId, $body);

        // ── permission-denied actor gets nothing from either surface ──
        // (404 — the document must not be revealed to exist).
        $this->actingAs($this->loader->docUser('nogrant@sterling.example'));
        $this->get(route('documents.activity', [$m, $docId]))->assertNotFound();
        $this->get(route('documents.activity.export', [$m]))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-test-');
        assert($path !== false);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdfBytes(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            ."4 0 obj<</Length 44>>stream\nBT /F1 24 Tf 100 700 Td ({$escaped}) Tj ET\nendstream\nendobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF";
    }

    private function previewDownloadUrl(string $html): ?string
    {
        if (! preg_match('/href="([^"]*\/download\?[^"]*)"/', $html, $m)) {
            return null;
        }

        return $m[1];
    }
}
