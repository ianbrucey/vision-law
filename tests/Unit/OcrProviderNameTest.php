<?php

namespace Tests\Unit;

use App\Services\Ocr\TesseractOcrProvider;
use Tests\TestCase;

/**
 * Pins the deployed OCR provider's identity string (used in the ocr_cost
 * log). Needs no Tesseract binary.
 */
class OcrProviderNameTest extends TestCase
{
    public function test_tesseract_provider_identifies_itself(): void
    {
        $this->assertSame('tesseract', (new TesseractOcrProvider)->providerName());
    }
}
