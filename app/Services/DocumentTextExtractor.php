<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Native (non-OCR) per-page text extraction (spec 007 T-06, DOC-15).
 *
 * Returns [page_number => text] for one blob's bytes + sniffed MIME.
 * Image-only content yields an empty array — the caller routes that to
 * OCR via documents.needs_ocr (T-03's MetadataExtractor sets it at
 * ingest).
 *
 * Strategies:
 * - PDF: pdftotext per page (poppler-utils, 007-D02).
 * - TXT/CSV: whole content as page 1.
 * - EML: text/plain body parts as page 1 (light multipart parse).
 * - DOCX/XLSX/PPTX: OOXML parts read natively via ZipArchive.
 * - Legacy Office (OLE): headless LibreOffice → PDF → pdftotext.
 * - HTML (authored fallback): tags stripped → page 1.
 *
 * This service may throw (missing binaries, corrupt input); the
 * pipeline treats any throw as `partial`, non-blocking.
 */
final class DocumentTextExtractor
{
    /**
     * @return array<int, string> 1-based page number → page text
     *
     * @throws \RuntimeException on extraction failure
     */
    public function extractPerPage(string $bytes, string $mime): array
    {
        return match (true) {
            $mime === 'application/pdf' => $this->extractPdfPages($bytes),
            $mime === 'text/plain' || $mime === 'text/csv' => $this->singlePage($bytes),
            $mime === 'message/rfc822' => $this->extractMail($bytes),
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($bytes),
            $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $this->extractXlsx($bytes),
            $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => $this->extractPptx($bytes),
            $mime === 'application/msword'
                || $mime === 'application/vnd.ms-excel'
                || $mime === 'application/vnd.ms-powerpoint' => $this->extractLegacyOffice($bytes),
            $mime === 'text/html' => $this->singlePage($this->htmlToText($bytes)),
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function extractPdfPages(string $bytes): array
    {
        $path = $this->spool($bytes, 'pdf');

        try {
            $pageCount = $this->pdfPageCount($path);
            if ($pageCount === null || $pageCount < 1) {
                throw new \RuntimeException('PDF page count unreadable.');
            }

            $pages = [];
            for ($page = 1; $page <= $pageCount; $page++) {
                $text = $this->run(
                    ['pdftotext', '-f', (string) $page, '-l', (string) $page, '-layout', $path, '-'],
                    60
                );
                $pages[$page] = $this->normalize($text);
            }

            return $pages;
        } finally {
            @unlink($path);
        }
    }

    private function pdfPageCount(string $path): ?int
    {
        try {
            $info = $this->run(['pdfinfo', $path], 30);
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/^Pages:\s+(\d+)/m', $info, $m) === 1) {
            return max(0, (int) $m[1]);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Plain text / mail / HTML
    // ------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function singlePage(string $text): array
    {
        return [1 => $this->normalize($text)];
    }

    /**
     * @return array<int, string>
     */
    private function extractMail(string $bytes): array
    {
        // Split headers from body at the first blank line.
        $parts = preg_split("/\r?\n\r?\n/", $bytes, 2);
        $body = $parts[1] ?? '';

        // Multipart: keep text/plain sections, drop attachments.
        if (str_contains(strtolower($parts[0] ?? ''), 'multipart')) {
            $sections = preg_split('/\r?\n--[^\\r\\n]*\r?\n/', $body) ?: [];
            $texts = [];
            foreach ($sections as $section) {
                [$head, $payload] = array_pad(preg_split("/\r?\n\r?\n/", $section, 2) ?: [], 2, '');
                if (str_contains(strtolower((string) $head), 'text/plain')) {
                    $texts[] = $this->decodeTransfer((string) $payload, (string) $head);
                }
            }
            $body = implode("\n\n", $texts);
        }

        $text = trim($body) === '' ? '' : $this->normalize($body);

        return [1 => $text];
    }

    private function decodeTransfer(string $payload, string $headers): string
    {
        if (str_contains(strtolower($headers), 'base64')) {
            $decoded = base64_decode($payload, true);

            return $decoded === false ? $payload : $decoded;
        }

        if (str_contains(strtolower($headers), 'quoted-printable')) {
            return quoted_printable_decode($payload);
        }

        return $payload;
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)[^>]*>.*?<\\/\\1>/is', '', $html) ?? '';
        $text = preg_replace('/<br\\s*\\/?>/i', "\n", $text) ?? '';
        $text = preg_replace('/<\\/(p|div|h[1-6]|li|tr)>/i', "\n", $text) ?? '';
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // OOXML (native, via ZipArchive)
    // ------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function extractDocx(string $bytes): array
    {
        $xml = $this->ooxmlPart($bytes, 'word/document.xml');
        $text = $this->xmlToText($xml);

        return [1 => $this->normalize($text)];
    }

    /**
     * @return array<int, string>
     */
    private function extractXlsx(string $bytes): array
    {
        $path = $this->spool($bytes, 'xlsx');

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Cannot open XLSX.');
            }

            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($sharedXml)) {
                preg_match_all('/<t[^>]*>(.*?)<\\/t>/s', $sharedXml, $m);
                $shared = array_map(
                    static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    $m[1]
                );
            }

            $pages = [];
            $sheetIndex = 0;
            for ($i = 1; ; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if (! is_string($sheetXml)) {
                    break;
                }
                $sheetIndex++;
                $cells = [];
                preg_match_all('/<c[^>]*>(.*?)<\\/c>/s', $sheetXml, $cm);
                foreach ($cm[1] as $cell) {
                    if (preg_match('/<v>(\\d+)<\\/v>/', $cell, $vm) === 1 && str_contains($cell, 't="s"')) {
                        $cells[] = $shared[(int) $vm[1]] ?? '';
                    } elseif (preg_match('/<v>(.*?)<\\/v>/s', $cell, $vm) === 1) {
                        $cells[] = $vm[1];
                    } elseif (preg_match('/<is>.*?<t[^>]*>(.*?)<\\/t>.*?<\\/is>/s', $cell, $im) === 1) {
                        $cells[] = html_entity_decode($im[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $text = implode("\n", array_filter(array_map('trim', $cells)));
                if ($text !== '') {
                    $pages[$sheetIndex] = $this->normalize($text);
                }
            }
            $zip->close();

            return $pages === [] ? [1 => ''] : $pages;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractPptx(string $bytes): array
    {
        $path = $this->spool($bytes, 'pptx');

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Cannot open PPTX.');
            }

            $pages = [];
            $slideIndex = 0;
            for ($i = 1; ; $i++) {
                $slideXml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if (! is_string($slideXml)) {
                    break;
                }
                $slideIndex++;
                $text = $this->xmlToText($slideXml);
                if (trim($text) !== '') {
                    $pages[$slideIndex] = $this->normalize($text);
                }
            }
            $zip->close();

            return $pages === [] ? [1 => ''] : $pages;
        } finally {
            @unlink($path);
        }
    }

    private function ooxmlPart(string $bytes, string $part): string
    {
        $path = $this->spool($bytes, 'zip');

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Cannot open OOXML package.');
            }
            $xml = $zip->getFromName($part);
            $zip->close();

            if (! is_string($xml)) {
                throw new \RuntimeException("OOXML part missing: {$part}.");
            }

            return $xml;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Word-processingML / PresentationML text runs → plain text.
     */
    private function xmlToText(string $xml): string
    {
        // Paragraph breaks first so runs don't glue across paragraphs.
        $xml = preg_replace('/<\\/(w:p|a:p|p:sp)>|<w:br\\s*\\/?>|<a:br\\s*\\/?>/', "\n", $xml) ?? '';
        preg_match_all('/<(?:w:t|a:t)[^>]*>(.*?)<\\/(?:w:t|a:t)>/s', $xml, $m);
        $runs = array_map(
            static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $m[1]
        );

        return implode('', $runs);
    }

    // ------------------------------------------------------------------
    // Legacy Office via headless LibreOffice
    // ------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function extractLegacyOffice(string $bytes): array
    {
        $in = $this->spool($bytes, 'doc');
        $outDir = sys_get_temp_dir().'/vl-office-'.bin2hex(random_bytes(8));
        mkdir($outDir);

        try {
            $this->run(
                [
                    (string) config('document.libreoffice.binary', 'soffice'),
                    '--headless', '--convert-to', 'pdf', '--outdir', $outDir, $in,
                ],
                120
            );

            $pdfs = glob($outDir.'/*.pdf');
            $pdfPath = $pdfs[0] ?? null;
            if (! is_string($pdfPath)) {
                throw new \RuntimeException('LibreOffice produced no PDF.');
            }

            return $this->extractPdfPages((string) file_get_contents($pdfPath));
        } finally {
            @unlink($in);
            foreach (glob($outDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($outDir);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function spool(string $bytes, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-extract-').'.'.$suffix;
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @param  list<string>  $command
     *
     * @throws \RuntimeException on non-zero exit
     */
    private function run(array $command, int $timeout): string
    {
        $process = new Process($command, null, null, null, $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Text extraction subprocess failed: '.implode(' ', $command));
        }

        return $process->getOutput();
    }

    /**
     * Whether a page carries no extractable text. pdftotext emits a bare
     * form feed for empty pages — and PHP's trim() does NOT strip \f —
     * so the blank check must name it explicitly.
     */
    public static function isBlank(string $text): bool
    {
        return trim($text, " \t\n\r\0\x0B\x0C") === '';
    }

    /**
     * Normalize whitespace: CRLF → LF, form feeds dropped, collapse 3+
     * blank lines, trim.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\f", '', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
