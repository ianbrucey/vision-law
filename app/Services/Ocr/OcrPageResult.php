<?php

namespace App\Services\Ocr;

/**
 * OCR outcome for one page (spec 007 T-06, DOC-16).
 *
 * @see OcrProvider
 */
final class OcrPageResult
{
    /**
     * @param  int  $pageNumber  1-based page number within the document
     * @param  string  $text  reconstructed page text (words grouped by line)
     * @param  float  $confidence  mean per-word confidence, 0..1
     * @param  list<array{t: string, c: float}>  $words  per-word text + confidence (0..1)
     * @param  int  $durationMs  wall-clock ms spent on this page (render + OCR)
     */
    public function __construct(
        public readonly int $pageNumber,
        public readonly string $text,
        public readonly float $confidence,
        public readonly array $words,
        public readonly int $durationMs,
    ) {}
}
