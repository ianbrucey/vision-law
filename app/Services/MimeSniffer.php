<?php

namespace App\Services;

/**
 * MIME sniffing from bytes (spec 007 T-03, DOC-01/DOC-04).
 *
 * PHP's finfo sees only magic bytes: OOXML documents (DOCX/XLSX/PPTX)
 * sniff as application/zip and legacy OLE documents (DOC/XLS/PPT/MSG)
 * as application/x-ole-storage — neither is on the allowlist, so plain
 * finfo would reject every Office upload. This sniffer refines those two
 * container types:
 *
 * - ZIP → opened as an OOXML package; the presence of
 *   word/document.xml, xl/workbook.xml, or ppt/presentation.xml decides
 *   the Office MIME. Anything else stays application/zip (rejected).
 * - OLE (D0 CF 11 E0 signature) → the FAT directory stores stream names
 *   as UTF-16LE; searching for the well-known names (WordDocument,
 *   Workbook, "PowerPoint Document") identifies the legacy formats, and
 *   MAPI property streams identify Outlook MSG.
 *
 * The client-supplied extension is never consulted.
 */
class MimeSniffer
{
    /**
     * OLE compound-document magic: D0 CF 11 E0 A1 B1 1A E1.
     */
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * @var array<string, string> OOXML part path → MIME
     */
    private const OOXML_PARTS = [
        'word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * @var array<string, string> UTF-16LE OLE stream name → MIME
     */
    private const OLE_STREAMS = [
        'WordDocument' => 'application/msword',
        'Workbook' => 'application/vnd.ms-excel',
        'PowerPoint Document' => 'application/vnd.ms-powerpoint',
    ];

    public function sniff(string $bytes): string
    {
        if ($bytes === '') {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);

        if ($mime === false) {
            $mime = 'application/octet-stream';
        }

        if ($mime === 'application/zip') {
            return $this->refineZip($bytes);
        }

        if ($mime === 'application/x-ole-storage' || str_starts_with($bytes, self::OLE_SIGNATURE)) {
            return $this->refineOle($bytes, $mime);
        }

        return $mime;
    }

    /**
     * Distinguish OOXML Office documents from plain ZIP archives by
     * inspecting the package parts.
     */
    private function refineZip(string $bytes): string
    {
        $path = $this->spoolToTemp($bytes);

        try {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return 'application/zip';
            }

            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name) && isset(self::OOXML_PARTS[$name])) {
                        return self::OOXML_PARTS[$name];
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        return 'application/zip';
    }

    /**
     * Distinguish legacy Office documents and Outlook MSG files inside
     * OLE containers via UTF-16LE stream-name search over the raw bytes.
     * The FAT directory always carries these names, so a byte search is
     * reliable without a full OLE parser.
     */
    private function refineOle(string $bytes, string $fallback): string
    {
        foreach (self::OLE_STREAMS as $streamName => $mime) {
            if (str_contains($bytes, $this->utf16le($streamName))) {
                return $mime;
            }
        }

        // Outlook MSG: MAPI named-property streams.
        if (str_contains($bytes, $this->utf16le('__properties_version1.0'))
            || str_contains($bytes, $this->utf16le('__substg1.0'))) {
            return 'application/vnd.ms-outlook';
        }

        return $fallback;
    }

    private function utf16le(string $ascii): string
    {
        $out = '';
        $len = strlen($ascii);
        for ($i = 0; $i < $len; $i++) {
            $out .= $ascii[$i]."\x00";
        }

        return $out;
    }

    /**
     * @return string temp path (caller unlinks)
     */
    private function spoolToTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-mime-');
        if ($path === false) {
            throw new \RuntimeException('Could not create temp file for MIME sniffing.');
        }
        file_put_contents($path, $bytes);

        return $path;
    }
}
