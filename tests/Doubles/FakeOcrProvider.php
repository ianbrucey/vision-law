<?php

namespace Tests\Doubles;

use App\Services\Ocr\OcrPageResult;
use App\Services\Ocr\OcrProvider;

/**
 * Hermetic stand-in for TesseractOcrProvider (spec 007, DOC-16).
 *
 * CI has no Tesseract binary. Returns deterministic page results containing
 * the word "kaleidoscope" so extraction/search tests can assert pipeline
 * behavior (needs_ocr flagging, text indexing, per-page cost logging)
 * without a live OCR engine.
 *
 * Bound in Tests\TestCase::setUp.
 */
class FakeOcrProvider implements OcrProvider
{
    /**
     * When false, pages come back with empty text (confidence 0) — for
     * tests exercising the "no text" paths (e.g. diff unavailable).
     * Reset to true in Tests\TestCase::setUp.
     */
    public static bool $withText = true;

    /**
     * @return array<int, OcrPageResult> keyed by 1-based page number
     */
    public function ocrPdf(string $pdfPath, int $pageCount): array
    {
        $pages = [];

        for ($i = 1; $i <= $pageCount; $i++) {
            $pages[$i] = $this->page($i);
        }

        return $pages;
    }

    public function ocrImage(string $imagePath, int $pageNumber): OcrPageResult
    {
        return $this->page($pageNumber);
    }

    public function providerName(): string
    {
        return 'fake';
    }

    private function page(int $pageNumber): OcrPageResult
    {
        if (! self::$withText) {
            return new OcrPageResult($pageNumber, '', 0.0, [], 5);
        }

        return new OcrPageResult(
            $pageNumber,
            "kaleidoscope\n",
            0.92,
            [['t' => 'kaleidoscope', 'c' => 0.92]],
            5,
        );
    }
}
