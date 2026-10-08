<?php

namespace App\Services\Ocr;

/**
 * OCR provider contract (spec 007 T-06, DOC-16; 007-D02 provider
 * interface for later).
 *
 * The deployed provider is native Tesseract (TesseractOcrProvider).
 * A future paid/API provider implements this interface and is swapped
 * via the container binding — callers never touch the implementation.
 *
 * Providers NEVER throw for unreadable pages: a page that cannot be
 * OCR'd yields an empty-text OcrPageResult (confidence 0) and the
 * pipeline marks the document `partial`, non-blocking.
 */
interface OcrProvider
{
    /**
     * OCR every page of a PDF file.
     *
     * @param  string  $pdfPath  local filesystem path to the PDF
     * @param  int  $pageCount  number of pages to OCR (1-based)
     * @return array<int, OcrPageResult> keyed by 1-based page number
     */
    public function ocrPdf(string $pdfPath, int $pageCount): array;

    /**
     * OCR a single raster image file (PNG/JPEG/TIFF page image).
     */
    public function ocrImage(string $imagePath, int $pageNumber): OcrPageResult;

    /**
     * Short provider identifier for the ocr_cost log (e.g. 'tesseract').
     */
    public function providerName(): string;
}
