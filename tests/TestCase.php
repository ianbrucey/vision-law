<?php

namespace Tests;

use App\Services\MalwareScanner;
use App\Services\Ocr\OcrProvider;
use App\Services\OfficePreviewService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Doubles\FakeMalwareScanner;
use Tests\Doubles\FakeOcrProvider;
use Tests\Doubles\FakeOfficePreviewService;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic external services: CI has no clamd, Tesseract, or
        // LibreOffice daemons/binaries. Feature tests assert app behavior
        // against these fakes; per-test failure paths use the static flags.
        // (2026-10-08: three 007 tests depended on live daemons and failed
        // CI while passing on the provisioned dev server.)
        $this->app->bind(MalwareScanner::class, FakeMalwareScanner::class);
        $this->app->bind(OcrProvider::class, FakeOcrProvider::class);
        $this->app->bind(OfficePreviewService::class, FakeOfficePreviewService::class);

        FakeMalwareScanner::$unreachable = false;
        FakeOcrProvider::$withText = true;
        FakeOfficePreviewService::$failConversion = false;
    }
}
