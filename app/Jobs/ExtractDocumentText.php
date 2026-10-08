<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentRendition;
use App\Models\DocumentVersion;
use App\Services\DocumentProcessingPipeline;
use App\Services\DocumentStore;
use App\Services\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Native per-page text extraction (spec 007 T-06, DOC-15).
 *
 * Idempotent per version: re-runs are no-ops once text rows exist or a
 * terminal stage (indexed/partial) was recorded. Never throws — any
 * failure marks the document `partial` (non-blocking; the document
 * stays usable) and logs only identifiers, never extracted text
 * (leak sentinel).
 *
 * Routing:
 * - pages with text → document_text_pages rows → `indexed`
 * - no extractable text + needs_ocr → `needs_ocr`, OCR dispatched
 * - no extractable text + not image-only → `partial`
 */
class ExtractDocumentText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $versionId) {}

    public function handle(
        DocumentTextExtractor $extractor,
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

        // Idempotency: rows exist or a terminal stage was recorded.
        if ($pipeline->hasTextRows($version) || $pipeline->isTerminal($document)) {
            return;
        }

        if ($document->status === 'quarantined' || $version->processing_status === 'failed') {
            return;
        }

        $pipeline->markStage($document, 'extracting');

        try {
            $blob = $this->sourceBlob($version, $document, $store);
            if (! $blob instanceof DocumentBlob) {
                $pipeline->markStage($document, 'partial');

                return;
            }

            $bytes = $store->get($blob)->getContents();
            $pages = $extractor->extractPerPage($bytes, (string) $blob->mime_sniffed);

            $nonEmpty = array_filter($pages, static fn (string $t): bool => ! DocumentTextExtractor::isBlank($t));

            if ($nonEmpty === []) {
                $this->routeEmptyExtraction($version, $document, $pipeline);

                return;
            }

            $pipeline->storeTextPages($version, $nonEmpty);
            $pipeline->markStage($document, 'indexed');
        } catch (\Throwable $e) {
            // Non-blocking: the document stays usable, honestly `partial`.
            // Log identifiers only — never extracted text.
            Log::warning('document.text_extraction.partial', [
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'error' => substr($e->getMessage(), 0, 200),
            ]);
            $pipeline->markStage($document->fresh() ?? $document, 'partial');
        }
    }

    /**
     * No native text found: image-only content goes to OCR; anything
     * else is honestly `partial`.
     */
    private function routeEmptyExtraction(
        DocumentVersion $version,
        Document $document,
        DocumentProcessingPipeline $pipeline,
    ): void {
        if ($document->needs_ocr) {
            $pipeline->dispatchOcr($version);

            return;
        }

        $pipeline->markStage($document, 'partial');
    }

    /**
     * Authored/generated documents store HTML on the version blob; the
     * extractable source is the generated PDF rendition (T-04). Every
     * other kind extracts from the version blob directly.
     */
    private function sourceBlob(
        DocumentVersion $version,
        Document $document,
        DocumentStore $store,
    ): ?DocumentBlob {
        if (in_array($document->kind, ['authored', 'generated'], true)) {
            $rendition = DocumentRendition::query()
                ->where('version_id', $version->getKey())
                ->where('kind', 'pdf')
                ->first();

            $blob = $rendition?->blob;
            if ($blob instanceof DocumentBlob) {
                return $blob;
            }

            return null;
        }

        return $version->blob;
    }
}
