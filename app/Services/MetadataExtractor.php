<?php

namespace App\Services;

use App\Models\DocumentVersion;
use Symfony\Component\Process\Process;

/**
 * Technical metadata extraction (spec 007 T-03, DOC-04/C-04).
 *
 * MIME allowlist validation plus per-type technical metadata:
 * - PDF: page count (pdfinfo, regex fallback), document properties,
 *   native-text vs image-only detection → needs_ocr (feeds T-06).
 * - Office (DOC/DOCX/XLS/XLSX/PPT/PPTX): OOXML app.xml/core.xml
 *   properties; page count from app.xml with a headless-conversion
 *   fallback; legacy OLE degrades gracefully.
 * - Images (PNG/JPG): dimensions via getimagesize, DPI best-effort
 *   (JPEG EXIF, PNG pHYs); raster → needs_ocr.
 * - TIFF: manual IFD walk for page count, dimensions, resolution;
 *   raster → needs_ocr.
 * - TXT/CSV/EML: light properties; native text → no OCR.
 *
 * Extraction NEVER throws for corrupt input: any failure yields an
 * ExtractionResult with status "partial" and the document stays usable
 * (documents.metadata_status = partial).
 */
class MetadataExtractor
{
    /**
     * @var list<string> office MIME families convertible to PDF
     */
    public const OFFICE_MIMES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private readonly MimeSniffer $sniffer,
        private readonly OfficePreviewService $office,
    ) {}

    /**
     * MIME allowlist validation (DOC-04). The sniffed MIME — never the
     * client extension — is checked against config(document.allowed_mimes).
     */
    public function validateMime(string $mime): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('document.allowed_mimes', []);

        return in_array($mime, $allowed, true);
    }

    /**
     * Sniff bytes with the container-aware sniffer and validate.
     *
     * @return array{mime: string, allowed: bool}
     */
    public function sniffAndValidate(string $bytes): array
    {
        $mime = $this->sniffer->sniff($bytes);

        return ['mime' => $mime, 'allowed' => $this->validateMime($mime)];
    }

    /**
     * Extract from a persisted version (bytes via DocumentStore).
     * Never throws: unreadable/quarantined bytes → partial result.
     */
    public function extract(DocumentVersion $version): ExtractionResult
    {
        try {
            $blob = $version->blob;
            if ($blob === null) {
                return ExtractionResult::partial(['version has no blob']);
            }

            $stream = app(DocumentStore::class)->get($blob);
            $bytes = $stream->getContents();

            return $this->extractBytes($bytes, (string) $blob->mime_sniffed);
        } catch (\Throwable $e) {
            return ExtractionResult::partial(['byte read failed: '.$e->getMessage()]);
        }
    }

    /**
     * Extract from raw bytes. Never throws: any failure → partial.
     */
    public function extractBytes(string $bytes, string $mime): ExtractionResult
    {
        try {
            return match (true) {
                $mime === 'application/pdf' => $this->extractPdf($bytes),
                in_array($mime, self::OFFICE_MIMES, true) => $this->extractOffice($bytes, $mime),
                $mime === 'image/png' || $mime === 'image/jpeg' => $this->extractRasterImage($bytes, $mime),
                $mime === 'image/tiff' => $this->extractTiff($bytes),
                $mime === 'text/plain' || $mime === 'text/csv' => $this->extractText($bytes, $mime),
                $mime === 'message/rfc822' => $this->extractMail($bytes),
                $mime === 'application/vnd.ms-outlook' => ExtractionResult::partial(['MSG properties not parsed; preview unavailable']),
                default => ExtractionResult::partial(["unsupported type for metadata extraction: {$mime}"]),
            };
        } catch (\Throwable $e) {
            return ExtractionResult::partial(['extraction failed: '.$e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    private function extractPdf(string $bytes): ExtractionResult
    {
        $path = $this->spool($bytes, 'pdf');
        $notes = [];

        try {
            $info = $this->run(['pdfinfo', $path], 30);
        } catch (\Throwable) {
            $info = null;
            $notes[] = 'pdfinfo unavailable; page count heuristic';
        }

        $pageCount = $info !== null ? $this->parsePdfInfoInt($info, 'Pages') : null;
        if ($pageCount === null) {
            // Heuristic fallback: count "/Type /Page" objects that are
            // not "/Type /Pages" (the page tree root).
            $pageCount = preg_match_all("/\/Type\s*\/Page(?!s)\b/", $bytes);
            $pageCount = $pageCount === false || $pageCount === 0 ? null : $pageCount;
            if ($pageCount === null) {
                return ExtractionResult::partial(['page count unreadable']);
            }
            $notes[] = 'page count via heuristic';
        }

        $properties = [];
        if ($info !== null) {
            foreach (['Title', 'Author', 'Creator', 'Producer', 'CreationDate', 'ModDate'] as $key) {
                $value = $this->parsePdfInfoString($info, $key);
                if ($value !== null && $value !== '') {
                    $properties[strtolower($key)] = $value;
                }
            }
        }

        // Native-text vs image-only: sample text from the first pages.
        // Below ~10 characters across up to 5 pages → scanned/image-only.
        $needsOcr = false;
        try {
            $samplePages = min($pageCount, 5);
            $text = $this->run(
                ['pdftotext', '-f', '1', '-l', (string) $samplePages, '-layout', $path, '-'],
                60
            );
            $needsOcr = $pageCount > 0 && strlen(trim($text)) < 10;
            if ($needsOcr) {
                $notes[] = 'no extractable text on sampled pages; flagged for OCR';
            }
        } catch (\Throwable) {
            $notes[] = 'text detection unavailable';
        }

        return new ExtractionResult(
            pageCount: $pageCount,
            properties: $properties,
            width: null,
            height: null,
            dpi: null,
            needsOcr: $needsOcr,
            status: 'ok',
            notes: $notes,
        );
    }

    // ------------------------------------------------------------------
    // Office
    // ------------------------------------------------------------------

    private function extractOffice(string $bytes, string $mime): ExtractionResult
    {
        $properties = [];
        $pageCount = null;
        $notes = [];

        if (str_starts_with($mime, 'application/vnd.openxmlformats')) {
            [$properties, $pageCount, $notes] = $this->extractOoxmlProperties($bytes, $mime);
        } else {
            $notes[] = 'legacy OLE properties not parsed';
        }

        // Page-count fallback: render to PDF headless and count there.
        // (OOXML app.xml <Pages> is only written by desktop Word; the
        // conversion path is authoritative.)
        if ($pageCount === null) {
            $pdf = $this->office->convertBytesToPdf($bytes, $mime);
            if ($pdf !== null) {
                $pdfResult = $this->extractPdf($pdf);
                $pageCount = $pdfResult->pageCount;
                if ($pageCount !== null) {
                    $notes[] = 'page count via headless conversion';
                }
            } else {
                $notes[] = 'headless conversion unavailable for page count';
            }
        }

        if ($pageCount === null && $properties === []) {
            return ExtractionResult::partial($notes);
        }

        return new ExtractionResult(
            pageCount: $pageCount,
            properties: $properties,
            width: null,
            height: null,
            dpi: null,
            needsOcr: false,
            status: 'ok',
            notes: $notes,
        );
    }

    /**
     * OOXML properties via DOM (getElementsByTagName matches local names
     * regardless of namespace prefixes — SimpleXML misses default-ns
     * elements like <Application> in app.xml).
     *
     * @return array{array<string, int|string>, ?int, list<string>}
     */
    private function extractOoxmlProperties(string $bytes, string $mime): array
    {
        $properties = [];
        $pageCount = null;
        $notes = [];

        $path = $this->spool($bytes, 'ooxml');
        try {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return [$properties, null, ['OOXML package unreadable']];
            }

            try {
                $appXml = $zip->getFromName('docProps/app.xml');
                if (is_string($appXml) && ($app = $this->loadXml($appXml)) !== null) {
                    foreach (['Application' => 'application', 'Company' => 'company', 'Manager' => 'manager', 'Template' => 'template'] as $tag => $prop) {
                        $value = $this->xmlText($app, $tag);
                        if ($value !== null) {
                            $properties[$prop] = $value;
                        }
                    }
                    foreach (['Words' => 'words', 'Characters' => 'characters', 'Lines' => 'lines', 'Paragraphs' => 'paragraphs'] as $tag => $prop) {
                        $value = $this->xmlText($app, $tag);
                        if ($value !== null && is_numeric($value)) {
                            $properties[$prop] = (int) $value;
                        }
                    }
                    $countTags = str_contains($mime, 'wordprocessingml')
                        ? ['Pages']
                        : (str_contains($mime, 'presentationml') ? ['Slides'] : []);
                    foreach ($countTags as $tag) {
                        $value = $this->xmlText($app, $tag);
                        if ($value !== null && is_numeric($value) && (int) $value > 0) {
                            $pageCount = (int) $value;
                        }
                    }
                }

                $coreXml = $zip->getFromName('docProps/core.xml');
                if (is_string($coreXml) && ($core = $this->loadXml($coreXml)) !== null) {
                    foreach (['title' => 'title', 'creator' => 'creator', 'description' => 'description', 'subject' => 'subject', 'keywords' => 'keywords', 'created' => 'created', 'modified' => 'modified', 'lastModifiedBy' => 'last_modified_by'] as $tag => $prop) {
                        $value = $this->xmlText($core, $tag);
                        if ($value !== null) {
                            $properties[$prop] = $value;
                        }
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        return [$properties, $pageCount, $notes];
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        $doc = new \DOMDocument;
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $ok = $doc->loadXML($xml);
        } finally {
            restore_error_handler();
        }

        return $ok ? $doc : null;
    }

    private function xmlText(\DOMDocument $doc, string $tag): ?string
    {
        $nodes = $doc->getElementsByTagName($tag);
        if ($nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    // ------------------------------------------------------------------
    // Raster images
    // ------------------------------------------------------------------

    private function extractRasterImage(string $bytes, string $mime): ExtractionResult
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            return ExtractionResult::partial(['image dimensions unreadable']);
        }

        [$width, $height] = [$size[0], $size[1]];
        $dpi = null;
        $properties = ['bits' => $size['bits'] ?? null, 'channels' => $size['channels'] ?? null];

        if ($mime === 'image/jpeg') {
            $exif = @exif_read_data('data://application/octet-stream;base64,'.base64_encode($bytes), 'IFD0', true);
            if (is_array($exif)) {
                $ifd0 = $exif['IFD0'] ?? [];
                if (isset($ifd0['XResolution']) && is_string($ifd0['XResolution'])) {
                    $dpi = $this->rationalToFloat($ifd0['XResolution']);
                }
                foreach (['Make', 'Model', 'DateTime'] as $key) {
                    if (isset($ifd0[$key]) && is_string($ifd0[$key]) && trim($ifd0[$key]) !== '') {
                        $properties[strtolower($key)] = trim($ifd0[$key]);
                    }
                }
            }
        } elseif ($mime === 'image/png') {
            $dpi = $this->pngDpi($bytes);
        }

        return new ExtractionResult(
            pageCount: 1,
            properties: array_filter($properties, fn ($v) => $v !== null),
            width: $width,
            height: $height,
            dpi: $dpi,
            needsOcr: true,
            status: 'ok',
            notes: [],
        );
    }

    /**
     * PNG pHYs chunk: pixels per unit; unit 1 = metre → DPI.
     */
    private function pngDpi(string $bytes): ?float
    {
        // Skip 8-byte signature; walk chunks.
        $offset = 8;
        $len = strlen($bytes);
        while ($offset + 8 <= $len) {
            $chunkLen = self::u32(substr($bytes, $offset, 4), false);
            $type = substr($bytes, $offset + 4, 4);
            if ($type === 'pHYs' && $chunkLen >= 9) {
                $data = substr($bytes, $offset + 8, 9);
                /** @var array{ppux: int, ppuy: int, unit: int} $parts */
                $parts = unpack('Nppux/Nppuy/Cunit', $data);
                if ($parts['unit'] === 1 && $parts['ppux'] > 0) {
                    return round($parts['ppux'] / 39.3701, 2);
                }

                return null;
            }
            if ($type === 'IDAT' || $type === 'IEND') {
                break;
            }
            $offset += 12 + $chunkLen;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // TIFF (manual IFD walk — no imagick on the box)
    // ------------------------------------------------------------------

    /**
     * unpack() returns array|false - these helpers normalize.
     */
    private static function u16(string $bytes, bool $le): int
    {
        $r = $le ? unpack('v', $bytes) : unpack('n', $bytes);

        return $r === false ? 0 : (int) $r[1];
    }

    private static function u32(string $bytes, bool $le): int
    {
        $r = $le ? unpack('V', $bytes) : unpack('N', $bytes);

        return $r === false ? 0 : (int) $r[1];
    }

    private function extractTiff(string $bytes): ExtractionResult
    {
        $len = strlen($bytes);
        if ($len < 8) {
            return ExtractionResult::partial(['TIFF header truncated']);
        }

        $header = substr($bytes, 0, 4);
        if ($header === "II*\x00") {
            $le = true;
        } elseif ($header === "MM\x00*") {
            $le = false;
        } else {
            return ExtractionResult::partial(['not a TIFF header']);
        }

        $u16 = fn (string $b): int => self::u16($b, $le);
        $u32 = fn (string $b): int => self::u32($b, $le);

        $pages = 0;
        $width = null;
        $height = null;
        $dpi = null;
        $nextIfd = $u32(substr($bytes, 4, 4));

        // Guard: never walk more than 10k IFDs off corrupt offsets.
        while ($nextIfd !== 0 && $pages < 10000) {
            if ($nextIfd + 2 > $len) {
                break;
            }
            $entries = $u16(substr($bytes, $nextIfd, 2));
            $ifdEnd = $nextIfd + 2 + $entries * 12;
            if ($ifdEnd + 4 > $len) {
                break;
            }

            $firstPage = $pages === 0;
            for ($i = 0; $i < $entries; $i++) {
                $entry = substr($bytes, $nextIfd + 2 + $i * 12, 12);
                $tag = $u16(substr($entry, 0, 2));
                $type = $u16(substr($entry, 2, 2));
                $count = $u32(substr($entry, 4, 4));
                $valueOffset = substr($entry, 8, 4);

                if (! $firstPage) {
                    continue;
                }

                $value = $this->tiffTagValue($bytes, $type, $count, $valueOffset, $le, $len);
                if ($value === null) {
                    continue;
                }
                if ($tag === 256) {
                    $width = (int) $value;
                } elseif ($tag === 257) {
                    $height = (int) $value;
                } elseif ($tag === 282 || $tag === 283) {
                    $dpi = $dpi ?? (float) $value;
                }
            }

            $pages++;
            $nextIfd = $u32(substr($bytes, $ifdEnd, 4));
        }

        if ($pages === 0) {
            return ExtractionResult::partial(['no TIFF directories found']);
        }

        return new ExtractionResult(
            pageCount: $pages,
            properties: [],
            width: $width,
            height: $height,
            dpi: $dpi !== null ? round($dpi, 2) : null,
            needsOcr: true,
            status: 'ok',
            notes: [],
        );
    }

    /**
     * Read a TIFF tag value: inline when it fits in 4 bytes, otherwise via
     * the offset. Handles SHORT/LONG/RATIONAL.
     */
    private function tiffTagValue(string $bytes, int $type, int $count, string $valueOffset, bool $le, int $len): ?float
    {
        $u16 = fn (string $b): int => self::u16($b, $le);
        $u32 = fn (string $b): int => self::u32($b, $le);

        $typeSize = [3 => 2, 4 => 4, 5 => 8][$type] ?? null;
        if ($typeSize === null || $count < 1) {
            return null;
        }

        $data = $valueOffset;
        if ($typeSize * $count > 4) {
            $at = $u32($valueOffset);
            if ($at + $typeSize > $len) {
                return null;
            }
            $data = substr($bytes, $at, $typeSize * $count);
        }

        return match ($type) {
            3 => (float) $u16(substr($data, 0, 2)),
            4 => (float) $u32(substr($data, 0, 4)),
            5 => $this->tiffRational($data, $le),
            default => null,
        };
    }

    private function tiffRational(string $data, bool $le): ?float
    {
        if (strlen($data) < 8) {
            return null;
        }
        $num = self::u32(substr($data, 0, 4), $le);
        $den = self::u32(substr($data, 4, 4), $le);

        return $den === 0 ? null : $num / $den;
    }

    // ------------------------------------------------------------------
    // Text / mail
    // ------------------------------------------------------------------

    private function extractText(string $bytes, string $mime): ExtractionResult
    {
        $properties = [
            'encoding' => mb_detect_encoding($bytes, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'unknown',
            'lines' => substr_count($bytes, "\n") + 1,
            'characters' => mb_strlen($bytes, 'UTF-8'),
        ];

        if ($mime === 'text/csv') {
            $firstLine = strtok($bytes, "\n");
            $properties['columns'] = $firstLine === false ? 0 : count(str_getcsv($firstLine));
        }

        return new ExtractionResult(
            pageCount: 1,
            properties: $properties,
            width: null,
            height: null,
            dpi: null,
            needsOcr: false,
            status: 'ok',
            notes: [],
        );
    }

    private function extractMail(string $bytes): ExtractionResult
    {
        $properties = [];
        $head = substr($bytes, 0, 16384);
        foreach (['From', 'To', 'Subject', 'Date'] as $field) {
            if (preg_match("/^{$field}:[ \t]*(.+?)$/mi", $head, $m)) {
                $properties[strtolower($field)] = trim($m[1]);
            }
        }

        return new ExtractionResult(
            pageCount: 1,
            properties: $properties,
            width: null,
            height: null,
            dpi: null,
            needsOcr: false,
            status: 'ok',
            notes: [],
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $argv
     *
     * @throws \RuntimeException when the binary fails
     */
    private function run(array $argv, int $timeoutSeconds): string
    {
        $process = new Process($argv);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'exit '.$process->getExitCode());
        }

        return $process->getOutput();
    }

    private function parsePdfInfoInt(string $info, string $key): ?int
    {
        if (preg_match("/^{$key}:\s*(\d+)\s*$/m", $info, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function parsePdfInfoString(string $info, string $key): ?string
    {
        if (preg_match("/^{$key}:\s*(.+?)\s*$/m", $info, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function rationalToFloat(string $rational): ?float
    {
        if (str_contains($rational, '/')) {
            [$num, $den] = explode('/', $rational, 2);
            if (is_numeric($num) && is_numeric($den) && (float) $den !== 0.0) {
                return round((float) $num / (float) $den, 2);
            }

            return null;
        }

        return is_numeric($rational) ? (float) $rational : null;
    }

    /**
     * @return string temp path (caller unlinks)
     */
    private function spool(string $bytes, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), "vl-extract-{$prefix}-");
        if ($path === false) {
            throw new \RuntimeException('Could not spool bytes for extraction.');
        }
        file_put_contents($path, $bytes);

        return $path;
    }
}
