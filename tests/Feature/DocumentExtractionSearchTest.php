<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use App\Models\DocumentTextPage;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-06 verdicts: extraction/OCR pipeline + full-text search.
 *
 * - test_extraction_pipeline: native PDF → per-page rows, tsvector
 *   populated; re-run → no-op (idempotent); corrupt/empty doc →
 *   `partial`, document still usable.
 * - test_ocr_flagging: scanned/image-only PDF → OCR text extracted,
 *   per-word confidences stored, low-confidence pages flagged; OCR text
 *   downloadable as a text layer.
 * - test_document_search (C-09 search half): native PDF + scanned PDF →
 *   OCR text searchable; results ranked with snippets + match counts;
 *   snippet click target opens preview at the matched page; ungranted
 *   user's search returns NOTHING (leak sentinel: no titles, no
 *   snippets, no counts).
 */
class DocumentExtractionSearchTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    private Matter $matter;

    private User $actor;

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
        $this->matter = $this->loader->docMatter('MAT-2026-001');
        // org_admin → matter access on all org matters (006 AccessControl).
        $this->actor = $this->loader->docUser('g.grant@sterling.example');

        $this->actingAs($this->actor);
    }

    // ------------------------------------------------------------------
    // Verdict 1 — extraction pipeline
    // ------------------------------------------------------------------

    public function test_extraction_pipeline(): void
    {
        $bytes = $this->nativePdf([
            'The sunflower file opens here on page one',
            'Moonlight on the second page of the file',
        ]);

        $document = $this->ingest($bytes, 'Extraction Test Doc', 'extract.pdf');
        $document = $document->fresh();
        $this->assertInstanceOf(Document::class, $document);

        // Native PDF → per-page rows, tsvector populated, stage indexed.
        $this->assertSame('indexed', $document->processing_stage);

        $versionId = $document->currentVersion->getKey();
        $pages = DocumentTextPage::query()
            ->where('version_id', $versionId)
            ->orderBy('page_number')
            ->get();

        $this->assertCount(2, $pages);
        $this->assertStringContainsString('sunflower', strtolower((string) $pages[0]->text));
        $this->assertStringContainsString('moonlight', strtolower((string) $pages[1]->text));

        // tsvector is populated (trigger-maintained) — FTS sees the rows.
        $this->assertTrue(
            DocumentTextPage::query()
                ->where('version_id', $versionId)
                ->whereRaw("text_tsv @@ plainto_tsquery('english', 'sunflower')")
                ->exists()
        );

        // Re-run → no-op (idempotent): no new rows, stage unchanged.
        $before = DocumentTextPage::query()->where('version_id', $versionId)->count();
        ExtractDocumentText::dispatch((string) $versionId);

        $this->assertSame(
            $before,
            DocumentTextPage::query()->where('version_id', $versionId)->count()
        );
        $this->assertSame('indexed', $document->fresh()->processing_stage);

        // Corrupt PDF → `partial`, document still usable (never silently
        // clean/ready, non-blocking).
        $corrupt = $this->ingest(
            "%PDF-1.4\n%corrupt-bytes-not-a-pdf\n",
            'Corrupt Doc',
            'corrupt.pdf',
            'application/pdf'
        )->fresh();
        $this->assertInstanceOf(Document::class, $corrupt);
        $this->assertSame('partial', $corrupt->processing_stage);
        $this->assertSame('ready', $corrupt->status);
        $this->assertSame(
            0,
            DocumentTextPage::query()->where('version_id', $corrupt->currentVersion->getKey())->count()
        );
    }

    // ------------------------------------------------------------------
    // Verdict 2 — OCR flagging
    // ------------------------------------------------------------------

    public function test_ocr_flagging(): void
    {
        $bytes = $this->scannedPdf(['kaleidoscope']);

        $document = $this->ingest($bytes, 'Scanned OCR Doc', 'scan.pdf');
        $document = $document->fresh();
        $this->assertInstanceOf(Document::class, $document);

        // Image-only PDF → flagged needs_ocr at ingest (T-03), OCR ran.
        $this->assertTrue($document->needs_ocr);
        $this->assertSame('indexed', $document->processing_stage);

        $versionId = $document->currentVersion->getKey();
        $pages = DocumentTextPage::query()
            ->where('version_id', $versionId)
            ->orderBy('page_number')
            ->get();

        $this->assertCount(1, $pages);

        // OCR text extracted, per-word confidences stored.
        $this->assertStringContainsString('kaleidoscope', strtolower((string) $pages[0]->text));
        $this->assertNotNull($pages[0]->ocr_confidence);
        $this->assertGreaterThan(0.5, (float) $pages[0]->ocr_confidence);
        $this->assertNotEmpty($pages[0]->ocr_words);

        // Per-page cost data logged ($0 for the fake; the provider interface
        // leaves the door open for a paid provider).
        $cost = $document->ocr_cost;
        $this->assertIsArray($cost);
        // The pipeline records the active provider's identity: in tests that's
        // the FakeOcrProvider (no Tesseract binary in CI). The real
        // provider's identity string is pinned hermetically by
        // tests/Unit/OcrProviderNameTest.php.
        $this->assertSame('fake', $cost['provider']);
        $this->assertSame(1, $cost['pages']);
        $this->assertEquals(0.0, $cost['estimated_cost_usd']);
        $this->assertGreaterThan(0, $cost['total_ms']);

        // Low-confidence pages flagged (deterministic via threshold).
        $this->assertSame([], $document->lowConfidencePages());
        Config::set('document.ocr.low_confidence_threshold', 0.9999);
        $this->assertSame([1], $document->fresh()->lowConfidencePages());

        // OCR text downloadable as a text layer.
        $response = $this->get(
            "/matters/{$this->matter->getKey()}/documents/{$document->getKey()}/text"
        );

        $response->assertOk();
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('kaleidoscope', strtolower((string) $response->streamedContent()));
        $this->assertNotEmpty($response->headers->get('X-Checksum-Sha256'));
    }

    // ------------------------------------------------------------------
    // Verdict 3 — full-text search (C-09 search half)
    // ------------------------------------------------------------------

    public function test_document_search(): void
    {
        $native = $this->ingest(
            $this->nativePdf([
                'The quick brown fox jumps over the fence',
                'Zebras graze at dawn near the river',
            ]),
            'Search Native Doc',
            'search-native.pdf'
        );

        $scanned = $this->ingest(
            $this->scannedPdf(['kaleidoscope']),
            'Search Scanned Doc',
            'search-scanned.pdf'
        );

        // --- Native text is searchable, ranked, with snippets + counts.
        $response = $this->getJson('/search/documents?content='.urlencode('zebras'));
        $response->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $hit = $data[0];
        $this->assertSame((string) $native->getKey(), $hit['document_id']);
        $this->assertSame('Search Native Doc', $hit['title']);
        $this->assertSame(2, $hit['page_number']);
        $this->assertStringContainsString('<mark>', $hit['snippet']);
        $this->assertGreaterThan(0, $hit['match_count']);
        $this->assertGreaterThan(0, $hit['rank']);

        // Snippet click target opens the preview at the matched page.
        $this->assertStringContainsString('page=2', $hit['preview_url']);
        $previewPath = (string) parse_url($hit['preview_url'], PHP_URL_PATH)
            .'?'.(string) parse_url($hit['preview_url'], PHP_URL_QUERY);
        $preview = $this->get($previewPath);
        $preview->assertOk();
        $this->assertStringContainsString('"initialPage":2', $preview->getContent());

        // --- OCR text is searchable.
        $ocrResponse = $this->getJson('/search/documents?content='.urlencode('kaleidoscope'));
        $ocrResponse->assertOk();
        $ocrData = $ocrResponse->json('data');
        $this->assertNotEmpty($ocrData);
        $this->assertSame((string) $scanned->getKey(), $ocrData[0]['document_id']);
        $this->assertSame(1, $ocrData[0]['page_number']);

        // --- Phrase query support.
        $phrase = $this->getJson('/search/documents?content='.urlencode('"quick brown"'));
        $phrase->assertOk();
        $this->assertNotEmpty($phrase->json('data'));
        $this->assertSame((string) $native->getKey(), $phrase->json('data.0.document_id'));

        // --- Exclusion support: self-excluding query → zero hits.
        $excluded = $this->getJson('/search/documents?content='.urlencode('zebras -zebras'));
        $excluded->assertOk();
        $this->assertSame([], $excluded->json('data'));

        // --- Leak sentinel: ungranted user gets NOTHING — no titles, no
        // snippets, no counts.
        $this->actingAs($this->loader->docUser('nogrant@sterling.example'));

        $denied = $this->getJson('/search/documents?content='.urlencode('zebras'));
        $denied->assertOk();
        $this->assertSame([], $denied->json('data'));
        $this->assertSame(0, $denied->json('meta.total'));

        $body = $denied->getContent();
        $this->assertStringNotContainsString('Search Native Doc', $body);
        $this->assertStringNotContainsString('Search Scanned Doc', $body);
        $this->assertStringNotContainsString('<mark>', $body);

        // --- Cross-tenant adversary: also zero hits.
        $this->actingAs($this->loader->docUser('spy@rival.example'));

        $spy = $this->getJson('/search/documents?content='.urlencode('kaleidoscope'));
        $spy->assertOk();
        $this->assertSame([], $spy->json('data'));
        $this->assertStringNotContainsString('Search Scanned Doc', $spy->getContent());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function ingest(
        string $bytes,
        string $title,
        string $filename,
        ?string $mime = null,
    ): Document {
        $meta = ['title' => $title, 'filename' => $filename];
        if ($mime !== null) {
            // Trusted internal override (same path the fixture loader
            // uses for synthetic bytes).
            $meta['mime'] = $mime;
        }

        return app(DocumentIngestService::class)->ingest($this->matter, $this->actor, $bytes, $meta);
    }

    private static function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * Minimal valid multi-page PDF with native text (one string per page).
     *
     * @param  list<string>  $pages
     */
    private function nativePdf(array $pages): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $objNum = 3;
        $kids = [];
        $pageDefs = [];
        foreach ($pages as $text) {
            $kids[] = "{$objNum} 0 R";
            $pageDefs[] = [$objNum, $objNum + 2, $text];
            $objNum += 3;
        }
        $fontNum = $objNum;

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';

        foreach ($pageDefs as [$pageObj, $contentObj, $text]) {
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                ."/Resources << /Font << /F1 {$fontNum} 0 R >> >> /Contents {$contentObj} 0 R >>";
            $stream = 'BT /F1 28 Tf 72 720 Td ('.self::pdfEscape($text).') Tj ET';
            $objects[$contentObj] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[$fontNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        return $this->assemblePdf($objects);
    }

    /**
     * Image-only PDF: each page is a rendered JPEG (no text layer).
     *
     * @param  list<string>  $pageTexts
     */
    private function scannedPdf(array $pageTexts): string
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $width = 1240;
        $height = 1754;

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $objNum = 3;
        $kids = [];
        $imageIndex = 1;
        foreach ($pageTexts as $text) {
            $image = imagecreatetruecolor($width, $height);
            assert($image !== false);
            $white = imagecolorallocate($image, 255, 255, 255);
            $black = imagecolorallocate($image, 0, 0, 0);
            imagefill($image, 0, 0, $white);

            $y = 160;
            foreach (explode("\n", wordwrap($text, 40, "\n")) as $line) {
                imagettftext($image, 46, 0, 100, $y, $black, $font, $line);
                $y += 84;
            }

            ob_start();
            imagejpeg($image, null, 92);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            $imageObj = $objNum++;
            $pageObj = $objNum++;
            $contentObj = $objNum++;
            $kids[] = "{$pageObj} 0 R";

            $objects[$imageObj] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} "
                .'/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\n"
                ."stream\n{$jpeg}\nendstream";
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                ."/Resources << /XObject << /Im{$imageIndex} {$imageObj} 0 R >> >> /Contents {$contentObj} 0 R >>";
            $content = "q 612 0 0 792 0 0 cm /Im{$imageIndex} Do Q";
            $objects[$contentObj] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
            $imageIndex++;
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pageTexts).' >>';

        return $this->assemblePdf($objects);
    }

    /**
     * @param  array<int, string>  $objects  object number → body
     */
    private function assemblePdf(array $objects): string
    {
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf('%010d 00000 n '."\n", $offsets[$i] ?? 0);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.($max + 1)." /Root 1 0 R >>\n"
            ."startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
