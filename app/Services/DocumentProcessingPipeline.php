<?php

namespace App\Services;

use App\Jobs\ExtractDocumentText;
use App\Jobs\OcrDocumentPages;
use App\Models\Document;
use App\Models\DocumentTextPage;
use App\Models\DocumentVersion;
use App\Services\Ocr\OcrPageResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Text extraction / OCR pipeline orchestrator (spec 007 T-06, DOC-15/16/17).
 *
 * Stage machine (documents.processing_stage):
 *   processing → extracting → indexed            (native text found)
 *   processing → extracting → needs_ocr → ocr_processing → indexed
 *   any stage → partial                            (failure: non-blocking,
 *                                                  document stays usable)
 *
 * Scan (T-02) already ran at ingest: this pipeline VERIFIES rather than
 * re-scans — a quarantined document is never extracted, and a version
 * still `scanning` is left alone until T-02's sweeper resolves the scan.
 * NOTHING is ever silently marked clean/ready: unknown states keep their
 * honest stage (processing/scanning/partial).
 *
 * Per-version stages stay on the DB-immutable document_versions row
 * (007-D06 — set at insert, never updated); the T-06 stage lives on the
 * mutable documents row, deliberately separate from the documents.status
 * rollup owned by T-02/T-05/T-09.
 *
 * Extraction is IDEMPOTENT per version: re-running is a no-op once rows
 * exist or a terminal stage (indexed/partial) was recorded. A unique
 * constraint on (version_id, page_number) guards the race.
 */
final class DocumentProcessingPipeline
{
    /**
     * Mirrors the documents_processing_stage_check constraint.
     *
     * @var list<string>
     */
    public const STAGES = [
        'processing',
        'extracting',
        'needs_ocr',
        'ocr_processing',
        'indexed',
        'partial',
    ];

    /**
     * Terminal stages: re-running the pipeline for such a document is a
     * no-op (idempotency contract).
     *
     * @var list<string>
     */
    public const TERMINAL_STAGES = ['indexed', 'partial'];

    /**
     * Kick off the pipeline for a document's current version. Queued;
     * safe to call from ingest (T-02) and publish (T-04) paths.
     */
    public function process(Document $document): void
    {
        $document->refresh();

        if ($document->status === 'quarantined') {
            // Quarantine contents are never extracted or indexed (leak
            // sentinel: quarantined bytes stay unreachable).
            return;
        }

        $version = $document->currentVersion;
        if (! $version instanceof DocumentVersion) {
            return;
        }

        if ($version->processing_status === 'scanning') {
            // T-02's scan hasn't resolved — leave the document honestly
            // `processing`; the sweeper retries the scan, not us.
            $this->markStage($document, 'processing');

            return;
        }

        if ($version->processing_status === 'failed') {
            return;
        }

        if ($this->isTerminal($document)) {
            return;
        }

        $this->markStage($document, 'processing');
        ExtractDocumentText::dispatch((string) $version->getKey());
    }

    /**
     * Dispatch OCR for a version already flagged needs_ocr. Idempotent.
     */
    public function dispatchOcr(DocumentVersion $version): void
    {
        $document = $version->document;
        if (! $document instanceof Document) {
            return;
        }

        if ($this->isTerminal($document) || $this->hasTextRows($version)) {
            return;
        }

        $this->markStage($document, 'needs_ocr');
        OcrDocumentPages::dispatch((string) $version->getKey());
    }

    public function markStage(Document $document, string $stage): void
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException("Unknown pipeline stage: {$stage}.");
        }

        $document->update(['processing_stage' => $stage]);
    }

    public function isTerminal(Document $document): bool
    {
        return in_array($document->processing_stage, self::TERMINAL_STAGES, true);
    }

    public function hasTextRows(DocumentVersion $version): bool
    {
        return DocumentTextPage::query()
            ->where('version_id', $version->getKey())
            ->exists();
    }

    /**
     * Record OCR cost data for the document (007-D02 provider interface:
     * native Tesseract first, cost $0; a paid provider fills
     * estimated_cost_usd later).
     *
     * @param  array<int, OcrPageResult>  $pages
     */
    public function recordOcrCost(Document $document, string $provider, array $pages, ?string $providerVersion): void
    {
        $perPageMs = [];
        foreach ($pages as $pageNumber => $result) {
            $perPageMs[(string) $pageNumber] = $result->durationMs;
        }

        $document->update([
            'ocr_cost' => [
                'provider' => $provider,
                'provider_version' => $providerVersion,
                'language' => (string) config('document.ocr.language', config('document.tesseract.language', 'eng')),
                'dpi' => (int) config('document.ocr.dpi', 300),
                'pages' => count($pages),
                'per_page_ms' => $perPageMs,
                'total_ms' => array_sum($perPageMs),
                'estimated_cost_usd' => 0.0,
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Insert page rows idempotently. check-then-insert for the common
     * path; the UNIQUE(version_id, page_number) constraint is the race
     * guard — a violation is swallowed as a no-op.
     *
     * @param  array<int, string>  $pages  1-based page number → text
     * @param  array<int, float>  $confidences  1-based page → 0..1 (OCR only)
     * @param  array<int, list<array{t: string, c: float}>>  $words  per-word confidences (OCR only)
     */
    public function storeTextPages(
        DocumentVersion $version,
        array $pages,
        array $confidences = [],
        array $words = [],
    ): int {
        $inserted = 0;

        foreach ($pages as $pageNumber => $text) {
            if (DocumentTextExtractor::isBlank($text)) {
                continue;
            }

            try {
                $created = DocumentTextPage::query()->firstOrCreate(
                    [
                        'version_id' => $version->getKey(),
                        'page_number' => $pageNumber,
                    ],
                    [
                        'text' => $text,
                        'ocr_confidence' => $confidences[$pageNumber] ?? null,
                        'ocr_words' => $words[$pageNumber] ?? null,
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $inserted++;
                }
            } catch (QueryException $e) {
                // UNIQUE race: another worker won — idempotent no-op.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                Log::warning('document.text_pages.race', [
                    'version_id' => (string) $version->getKey(),
                    'page' => $pageNumber,
                ]);
            }
        }

        return $inserted;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage()), 'duplicate key');
    }
}
