<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentVersion;
use App\Services\DocumentProcessingPipeline;
use App\Services\DocumentStore;
use App\Services\DocumentTextExtractor;
use App\Services\Ocr\OcrPageResult;
use App\Services\Ocr\OcrProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Tesseract OCR for image-only documents (spec 007 T-06, DOC-16).
 *
 * Image-only PDFs are rendered per page at 300 DPI; single raster
 * images (PNG/JPEG/TIFF) are OCR'd directly as page 1. Per-page rows
 * carry the OCR text, mean per-word confidence, and per-word
 * confidences; pages below the configured threshold surface via
 * Document::lowConfidencePages().
 *
 * Provider is injected (native Tesseract; 007-D02 leaves the interface
 * open for a paid provider later). Idempotent per version, never
 * throwing: failure → `partial`, non-blocking, identifiers-only
 * logging (leak sentinel).
 */
class OcrDocumentPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $versionId) {}

    public function handle(
        OcrProvider $ocr,
        DocumentProcessingPipeline $pipeline,
        DocumentStore $store,
    ): void {
        $version = DocumentVersion::query()->find($this->versionId);
        if (! $version instanceof DocumentVersion) {
            return;
        }

        $document = $version->document;
        if (! $document instanceof Document) {
            return;
        }

        if ($pipeline->hasTextRows($version) || $pipeline->isTerminal($document)) {
            return;
        }

        if ($document->status === 'quarantined' || $version->processing_status === 'failed') {
            return;
        }

        $pipeline->markStage($document, 'ocr_processing');

        $sourcePath = null;

        try {
            $blob = $version->blob;
            if (! $blob instanceof DocumentBlob) {
                $pipeline->markStage($document, 'partial');

                return;
            }

            $sourcePath = $this->spoolBlob($blob, $store);
            if ($sourcePath === null) {
                $pipeline->markStage($document, 'partial');

                return;
            }

            $mime = (string) $blob->mime_sniffed;

            /** @var array<int, OcrPageResult> $results */
            $results = str_starts_with($mime, 'image/')
                ? [1 => $ocr->ocrImage($sourcePath, 1)]
                : $this->ocrPdfPages($ocr, $sourcePath, $version);

            if ($results === []) {
                $pipeline->markStage($document, 'partial');

                return;
            }

            $pages = [];
            $confidences = [];
            $words = [];
            foreach ($results as $pageNumber => $result) {
                $pages[$pageNumber] = $result->text;
                $confidences[$pageNumber] = $result->confidence;
                $words[$pageNumber] = $result->words;
            }

            $pipeline->storeTextPages($version, $pages, $confidences, $words);
            $pipeline->recordOcrCost($document->fresh() ?? $document, $ocr->providerName(), $results, $this->tesseractVersion());

            $anyText = array_filter($pages, static fn (string $t): bool => ! DocumentTextExtractor::isBlank($t)) !== [];
            $pipeline->markStage($document->fresh() ?? $document, $anyText ? 'indexed' : 'partial');
        } catch (\Throwable $e) {
            Log::warning('document.ocr.partial', [
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'error' => substr($e->getMessage(), 0, 200),
            ]);
            $pipeline->markStage($document->fresh() ?? $document, 'partial');
        } finally {
            if (is_string($sourcePath) && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * @return array<int, OcrPageResult>
     */
    private function ocrPdfPages(OcrProvider $ocr, string $pdfPath, DocumentVersion $version): array
    {
        $pageCount = $version->page_count ?? $this->pdfPageCount($pdfPath);
        if ($pageCount === null || $pageCount < 1) {
            return [];
        }

        return $ocr->ocrPdf($pdfPath, $pageCount);
    }

    private function spoolBlob(DocumentBlob $blob, DocumentStore $store): ?string
    {
        try {
            $bytes = $store->get($blob)->getContents();
        } catch (\Throwable) {
            return null;
        }

        if (trim($bytes) === '') {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'vl-ocr-src-');
        if ($path === false) {
            return null;
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    private function pdfPageCount(string $path): ?int
    {
        try {
            $process = new Process(['pdfinfo', $path], null, null, null, 30);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            if (preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m) === 1) {
                return max(0, (int) $m[1]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function tesseractVersion(): ?string
    {
        try {
            $process = new Process([(string) config('document.tesseract.binary', 'tesseract'), '--version'], null, null, null, 10);
            $process->run();
            if ($process->isSuccessful() && preg_match('/tesseract\s+([\d.]+)/i', $process->getOutput(), $m) === 1) {
                return $m[1];
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
