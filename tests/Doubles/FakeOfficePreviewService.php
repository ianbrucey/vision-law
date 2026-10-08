<?php

namespace Tests\Doubles;

use App\Services\OfficePreviewService;

/**
 * Hermetic stand-in for the LibreOffice-backed OfficePreviewService
 * (spec 007, C-05).
 *
 * CI has no soffice binary. Overrides only the byte-conversion seam;
 * the inherited ensurePdf() still exercises the real rendition
 * caching/persistence logic.
 *
 * self::$failConversion = true simulates a broken document (or missing
 * binaries) → null → the controller's graceful "preview unavailable" path.
 *
 * Bound in Tests\TestCase::setUp.
 */
class FakeOfficePreviewService extends OfficePreviewService
{
    public static bool $failConversion = false;

    public function convertBytesToPdf(string $bytes, string $mime): ?string
    {
        if (self::$failConversion) {
            return null;
        }

        return "%PDF-1.4\n%fake-office-preview\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }
}
