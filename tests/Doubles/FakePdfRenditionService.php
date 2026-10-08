<?php

namespace Tests\Doubles;

use App\Models\DocumentBlob;
use App\Services\DocumentStore;
use App\Services\PdfRenditionException;
use App\Services\PdfRenditionService;

/**
 * Hermetic stand-in for the LibreOffice-backed PdfRenditionService
 * (spec 007, T-04).
 *
 * CI has no soffice binary. Produces a minimal, sniffable PDF blob
 * through the real DocumentStore, so the publish/template flows keep
 * exercising version + rendition persistence end to end.
 *
 * self::$failConversion = true simulates a conversion failure →
 * PdfRenditionException, the service's documented failure mode.
 *
 * Bound in Tests\TestCase::setUp.
 */
class FakePdfRenditionService extends PdfRenditionService
{
    public static bool $failConversion = false;

    public function __construct(private readonly DocumentStore $documentStore)
    {
        parent::__construct($documentStore);
    }

    public function fromHtml(string $html): DocumentBlob
    {
        if (self::$failConversion) {
            throw new PdfRenditionException('LibreOffice conversion failed: fake failure');
        }

        return $this->documentStore->put(
            "%PDF-1.4\n%fake-pdf-rendition\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
            ['mime' => 'application/pdf']
        );
    }
}
