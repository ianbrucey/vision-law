<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentRendition;
use App\Models\DocumentVersion;
use App\Models\MatterGrant;
use App\Services\DocumentSignedUrl;
use App\Services\DocumentStore;
use App\Services\MalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Doubles\FakeOfficePreviewService;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-03 verdicts: metadata extraction, preview gating, download.
 *
 * - test_metadata_extraction (C-04): upload DOCX → page count +
 *   properties extracted; image-only PDF → flagged needs_ocr; corrupt
 *   file → partial, document still usable.
 * - test_preview_gating (C-05): signed URL works; revoke grant → same
 *   URL → 403 on next range request; Office doc → converted preview
 *   renders; conversion failure → graceful "preview unavailable" state.
 * - test_download_integrity (C-06): download v2 → bytes match upload,
 *   checksum header matches SHA-256; document.downloaded audit row
 *   written; quarantined → 403.
 */
class DocumentPreviewTest extends TestCase
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
    // C-04 — metadata extraction
    // ------------------------------------------------------------------

    public function test_metadata_extraction(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $url = "/matters/{$matter->getKey()}/documents/upload";

        // DOCX → page count + properties extracted.
        $docx = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->docxBytes(), 'fee-agreement.docx'), 'title' => 'Fee agreement'],
            ['Accept' => 'application/json']
        );
        $docx->assertCreated();
        $doc = Document::findOrFail($docx->json('data.id'));

        $this->assertSame('ok', $doc->metadata_status);
        $this->assertFalse((bool) $doc->needs_ocr);
        $this->assertSame(4, $doc->currentVersion->page_count);
        $this->assertSame('fee-agreement.docx', $doc->currentVersion->original_filename);

        $props = $doc->metadata['properties'] ?? [];
        $this->assertSame('Fee Agreement', $props['title'] ?? null);
        $this->assertSame('A. Attorney', $props['creator'] ?? null);
        $this->assertSame('Vision Law Test', $props['application'] ?? null);

        // Native PDF with text → not flagged for OCR.
        $native = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->buildPdf(['Hello page one', 'Hello page two']), 'native.pdf'), 'title' => 'Native'],
            ['Accept' => 'application/json']
        );
        $native->assertCreated();
        $nativeDoc = Document::findOrFail($native->json('data.id'));
        $this->assertSame('ok', $nativeDoc->metadata_status);
        $this->assertSame(2, $nativeDoc->currentVersion->page_count);
        $this->assertFalse((bool) $nativeDoc->needs_ocr);

        // Image-only PDF → flagged needs_ocr (feeds T-06).
        $scanned = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->buildPdf(['']), 'scan.pdf'), 'title' => 'Scanned'],
            ['Accept' => 'application/json']
        );
        $scanned->assertCreated();
        $scannedDoc = Document::findOrFail($scanned->json('data.id'));
        $this->assertSame('ok', $scannedDoc->metadata_status);
        $this->assertSame(1, $scannedDoc->currentVersion->page_count);
        $this->assertTrue((bool) $scannedDoc->needs_ocr);

        // Corrupt file → partial, document still usable.
        $corrupt = $this->post(
            $url,
            ['file' => $this->uploadedFile("%PDF-1.4\n".random_bytes(96), 'corrupt.pdf'), 'title' => 'Corrupt'],
            ['Accept' => 'application/json']
        );
        $corrupt->assertCreated();
        $corruptDoc = Document::findOrFail($corrupt->json('data.id'));
        $this->assertSame('partial', $corruptDoc->metadata_status);
        $this->assertSame('ready', $corruptDoc->status);

        // …and still previewable (graceful degradation, not a dead row).
        $preview = $this->get("/matters/{$matter->getKey()}/documents/{$corruptDoc->getKey()}/preview");
        $preview->assertOk();
    }

    // ------------------------------------------------------------------
    // C-05 — preview gating
    // ------------------------------------------------------------------

    public function test_preview_gating(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $admin = $this->loader->docUser('g.grant@sterling.example');
        $viewer = $this->loader->docUser('v.viewer@sterling.example');

        // Viewer holds a direct matter grant (revocable below).
        $grant = MatterGrant::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => 'editor',
            'granted_by' => $admin->getKey(),
        ]);

        $this->actingAs($viewer);
        $url = "/matters/{$matter->getKey()}/documents/upload";

        $upload = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->buildPdf(['Preview me']), 'preview.pdf'), 'title' => 'Preview target'],
            ['Accept' => 'application/json']
        );
        $upload->assertCreated();
        $doc = Document::findOrFail($upload->json('data.id'));

        // Preview page renders and audits document.previewed.
        $page = $this->get("/matters/{$matter->getKey()}/documents/{$doc->getKey()}/preview");
        $page->assertOk();
        $page->assertSee('window.__previewConfig', false);
        $this->assertTrue($this->auditFor('document.previewed', (string) $doc->getKey()));

        $fileUrl = $this->previewFileUrl($page->getContent());
        $this->assertNotNull($fileUrl);

        // Signed URL serves the bytes…
        $file = $this->get($fileUrl);
        $file->assertOk();
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $file->streamedContent());

        // …including range requests (pdf.js page fetching).
        $range = $this->get($fileUrl, ['Range' => 'bytes=0-9']);
        $range->assertStatus(206);
        $this->assertMatchesRegularExpression(
            '{^bytes 0-9/\\d+$}',
            (string) $range->headers->get('Content-Range')
        );

        // Revoke the grant → the SAME signed URL → 403 on the next
        // request (permission re-checked every time, incl. ranges).
        $grant->delete();

        $revoked = $this->get($fileUrl);
        $revoked->assertForbidden();
        $revoked->assertJsonPath('code', 'forbidden');
        // Leak sentinel: no title in the denial body.
        $this->assertStringNotContainsString('Preview target', $revoked->getContent());

        $revokedRange = $this->get($fileUrl, ['Range' => 'bytes=0-9']);
        $revokedRange->assertForbidden();

        // Office document → converted PDF preview renders. Hermetic: the
        // base TestCase binds FakeOfficePreviewService (no soffice in CI);
        // the inherited ensurePdf() still exercises rendition caching.
        FakeOfficePreviewService::$failConversion = false;
        $this->actingAs($admin);
        $office = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->docxBytes(), 'memo.docx'), 'title' => 'Office memo'],
            ['Accept' => 'application/json']
        );
        $office->assertCreated();
        $officeDoc = Document::findOrFail($office->json('data.id'));

        $officePage = $this->get("/matters/{$matter->getKey()}/documents/{$officeDoc->getKey()}/preview");
        $officePage->assertOk();

        $officeFileUrl = $this->previewFileUrl($officePage->getContent());
        $this->assertNotNull($officeFileUrl);

        $converted = $this->get($officeFileUrl);
        $converted->assertOk();
        $this->assertSame('application/pdf', $converted->headers->get('Content-Type'));
        // The source was a ZIP (DOCX) — converted bytes are a real PDF.
        $this->assertStringStartsWith('%PDF', $converted->streamedContent());

        // Conversion cached as a derived artifact linked to the version.
        $this->assertTrue(
            DocumentRendition::query()
                ->where('version_id', $officeDoc->currentVersion->getKey())
                ->where('kind', 'pdf')
                ->exists()
        );

        // Conversion failure → graceful state, not an error page. The fake
        // simulates the failure path (broken document or missing binaries).
        FakeOfficePreviewService::$failConversion = true;
        $broken = $this->post(
            $url,
            ['file' => $this->uploadedFile($this->corruptDocxBytes(), 'broken.docx'), 'title' => 'Broken conv'],
            ['Accept' => 'application/json']
        );
        $broken->assertCreated();
        $brokenDoc = Document::findOrFail($broken->json('data.id'));

        fwrite(STDERR, '
BROKEN mime: '.$brokenDoc->currentVersion->blob->mime_sniffed.'
');
        fwrite(STDERR, 'BROKEN vs memo: '.$brokenDoc->getKey().' vs '.$officeDoc->getKey().'
');
        $brokenPage = $this->get("/matters/{$matter->getKey()}/documents/{$brokenDoc->getKey()}/preview");
        $brokenPage->assertOk();
        $brokenPage->assertSee('Preview unavailable');
        $brokenPage->assertSee('Download instead');
    }

    // ------------------------------------------------------------------
    // C-06 — download integrity
    // ------------------------------------------------------------------

    public function test_download_integrity(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $actor = $this->loader->docUser('g.grant@sterling.example');
        $url = "/matters/{$matter->getKey()}/documents/upload";

        $v1Bytes = $this->buildPdf(['version one']);
        $upload = $this->post(
            $url,
            ['file' => $this->uploadedFile($v1Bytes, 'contract.pdf'), 'title' => 'Contract'],
            ['Accept' => 'application/json']
        );
        $upload->assertCreated();
        $doc = Document::findOrFail($upload->json('data.id'));

        // v2 with different bytes (T-07 owns the versioning UI; the row
        // contract is exercised directly here).
        $v2Bytes = $this->buildPdf(['version two', 'second page']);
        $blob2 = app(DocumentStore::class)->put($v2Bytes, ['mime' => 'application/pdf']);
        $v2 = DocumentVersion::create([
            'document_id' => $doc->getKey(),
            'version_number' => 2,
            'blob_id' => $blob2->getKey(),
            'processing_status' => 'ready',
            'page_count' => 2,
            'original_filename' => 'contract-v2.pdf',
            'created_by' => $actor->getKey(),
        ]);
        $doc->update(['current_version_id' => $v2->getKey()]);

        $page = $this->get("/matters/{$matter->getKey()}/documents/{$doc->getKey()}/preview");
        $page->assertOk();
        $downloadUrl = $this->previewDownloadUrl($page->getContent());
        $this->assertNotNull($downloadUrl);

        // Download v2 → exact original bytes, integrity header, filename.
        $download = $this->get($downloadUrl);
        $download->assertOk();
        $this->assertSame($v2Bytes, $download->streamedContent());
        $this->assertSame(hash('sha256', $v2Bytes), $download->headers->get('X-Checksum-Sha256'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('contract-v2.pdf', (string) $download->headers->get('Content-Disposition'));

        // Every download writes document.downloaded.
        $this->assertTrue($this->auditFor('document.downloaded', (string) $doc->getKey()));

        // Quarantined → 403 on download (and preview).
        $matter2 = $this->loader->docMatter('MAT-2026-002');
        $infected = $this->post(
            "/matters/{$matter2->getKey()}/documents/upload",
            ['file' => $this->uploadedFile(MalwareScanner::EICAR_TEST_STRING, 'invoice.exe.pdf'), 'title' => 'Suspicious'],
            ['Accept' => 'application/json']
        );
        $infected->assertCreated();
        $this->assertSame('quarantined', $infected->json('data.status'));
        $quarantinedDoc = Document::findOrFail($infected->json('data.id'));

        $signed = app(DocumentSignedUrl::class)->generate($actor, $quarantinedDoc);
        $deniedDownload = $this->get(
            "/matters/{$matter2->getKey()}/documents/{$quarantinedDoc->getKey()}/download?{$signed}"
        );
        $deniedDownload->assertForbidden();
        $deniedDownload->assertJsonPath('code', 'quarantined');
        $this->assertStringNotContainsString('Suspicious', $deniedDownload->getContent());

        $deniedPreview = $this->get(
            "/matters/{$matter2->getKey()}/documents/{$quarantinedDoc->getKey()}/preview"
        );
        $deniedPreview->assertForbidden();
        $deniedPreview->assertJsonPath('code', 'quarantined');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function auditFor(string $event, string $documentId): bool
    {
        return AuditEvent::query()
            ->where('event', $event)
            ->where('payload->document_id', $documentId)
            ->exists();
    }

    private function previewFileUrl(string $html): ?string
    {
        if (! preg_match('/window\\.__previewConfig\\s*=\\s*(\\{.*?\\});/s', $html, $m)) {
            return null;
        }
        $config = json_decode($m[1], true);

        return is_array($config) && isset($config['fileUrl']) ? (string) $config['fileUrl'] : null;
    }

    private function previewDownloadUrl(string $html): ?string
    {
        if (! preg_match('/href="([^"]*\\/download\\?[^"]*)"/', $html, $m)) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    private function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-test-');
        assert($path !== false);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * DOCX-shaped bytes (valid ZIP, word/document.xml present so the
     * sniffer reports OOXML) with malformed document.xml — headless
     * conversion fails and the preview must degrade gracefully.
     */
    private function corruptDocxBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-docx-');
        assert($path !== false);
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', 'this is not valid xml <<<>>>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Minimal valid PDF with the given page texts ("" = no text layer).
     *
     * @param  list<string>  $pageTexts
     */
    private function buildPdf(array $pageTexts): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 3;

        foreach ($pageTexts as $text) {
            $pageObj = $next++;
            $kids[] = "{$pageObj} 0 R";
            if ($text !== '') {
                $contentObj = $next++;
                $stream = 'BT /F1 24 Tf 100 700 Td ('.$this->pdfEscape($text).') Tj ET';
                $objects[$contentObj] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
                $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentObj} 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>";
            } else {
                $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>';
            }
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pageTexts).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * Minimal DOCX with app.xml (Pages=4) and core.xml properties.
     * The text marker keeps each file byte-distinct (the store dedupes
     * identical bytes).
     */
    private function docxBytes(string $marker = 'Fee agreement test'): string
    {
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$marker.'</w:t></w:r></w:p></w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
            'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Vision Law Test</Application><Company>Sterling</Company><Pages>4</Pages><Words>120</Words></Properties>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Fee Agreement</dc:title><dc:creator>A. Attorney</dc:creator></cp:coreProperties>',
        ];

        $path = tempnam(sys_get_temp_dir(), 'vl-docx-');
        assert($path !== false);
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
