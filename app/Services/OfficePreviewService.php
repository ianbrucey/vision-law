<?php

namespace App\Services;

use App\Models\DocumentBlob;
use App\Models\DocumentRendition;
use App\Models\DocumentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Office/TIFF → PDF conversion for previews (spec 007 T-03, DOC-05).
 *
 * - Office (DOC/DOCX/XLS/XLSX/PPT/PPTX): headless `soffice --convert-to
 *   pdf` (007-D02, installed natively).
 * - TIFF: `tiff2pdf` (multi-page TIFFs become multi-page PDFs, so page
 *   navigation comes free through the pdf.js viewer).
 *
 * Converted PDFs are cached as derived artifacts in document_renditions
 * (kind "pdf"), linked to the SOURCE version — a new version is a new
 * version_id, so the cache invalidates naturally and history never
 * rewrites (007-D06). Blobs dedupe by SHA-256 inside DocumentStore.
 *
 * Conversion failure returns null: callers render the graceful
 * "preview unavailable — download instead" state, never an error page.
 */
class OfficePreviewService
{
    /**
     * @var array<string, string> MIME → temp-file extension for soffice
     */
    private const OFFICE_EXTENSIONS = [
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function __construct(
        private readonly DocumentStore $store,
    ) {}

    /**
     * Ensure a PDF rendition exists for the version; convert on first
     * miss. Returns the rendition blob, or null when conversion fails
     * (graceful preview-unavailable state).
     */
    public function ensurePdf(DocumentVersion $version): ?DocumentBlob
    {
        $existing = DocumentRendition::query()
            ->where('version_id', $version->getKey())
            ->where('kind', 'pdf')
            ->first();

        if ($existing instanceof DocumentRendition) {
            $blob = $existing->blob;
            if ($blob instanceof DocumentBlob) {
                return $blob;
            }
        }

        try {
            $source = $version->blob;
            if (! $source instanceof DocumentBlob) {
                return null;
            }

            $bytes = $this->store->get($source)->getContents();
            $pdf = $this->convertBytesToPdf($bytes, (string) $source->mime_sniffed);

            if ($pdf === null) {
                return null;
            }

            $blob = $this->store->put($pdf, ['mime' => 'application/pdf']);

            try {
                DocumentRendition::create([
                    'version_id' => $version->getKey(),
                    'kind' => 'pdf',
                    'blob_id' => $blob->getKey(),
                ]);
            } catch (QueryException) {
                // Lost a conversion race on the UNIQUE (version_id, kind):
                // the winner's row is authoritative.
                $winner = DocumentRendition::query()
                    ->where('version_id', $version->getKey())
                    ->where('kind', 'pdf')
                    ->first();
                if ($winner instanceof DocumentRendition && $winner->blob instanceof DocumentBlob) {
                    return $winner->blob;
                }
            }

            return $blob;
        } catch (\Throwable $e) {
            Log::warning('Office preview conversion failed', [
                'version_id' => (string) $version->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convert raw bytes to PDF bytes (no persistence). Null on any
     * failure — including missing binaries.
     */
    public function convertBytesToPdf(string $bytes, string $mime): ?string
    {
        try {
            if ($mime === 'image/tiff') {
                return $this->tiffToPdf($bytes);
            }

            $extension = self::OFFICE_EXTENSIONS[$mime] ?? null;
            if ($extension === null) {
                return null;
            }

            return $this->sofficeToPdf($bytes, $extension);
        } catch (\Throwable $e) {
            Log::warning('Preview conversion failed', ['mime' => $mime, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Whether this MIME has a preview-conversion path at all.
     */
    public static function convertible(string $mime): bool
    {
        return isset(self::OFFICE_EXTENSIONS[$mime]) || $mime === 'image/tiff';
    }

    private function sofficeToPdf(string $bytes, string $extension): ?string
    {
        $binary = (string) config('document.libreoffice.binary', 'soffice');
        $workdir = $this->workdir();

        try {
            $source = $workdir.'/source.'.$extension;
            file_put_contents($source, $bytes);

            // A private UserInstallation keeps parallel conversions from
            // colliding on the single soffice profile lock.
            $process = new Process([
                $binary,
                '-env:UserInstallation=file://'.$workdir.'/profile',
                '--headless',
                '--convert-to', 'pdf',
                '--outdir', $workdir,
                $source,
            ]);
            $process->setTimeout(120);
            $process->run();

            $output = $workdir.'/source.pdf';
            if (! $process->isSuccessful() || ! is_file($output)) {
                return null;
            }

            $pdf = file_get_contents($output);

            return $pdf === false || $pdf === '' ? null : $pdf;
        } finally {
            $this->removeDir($workdir);
        }
    }

    private function tiffToPdf(string $bytes): ?string
    {
        $workdir = $this->workdir();

        try {
            $source = $workdir.'/source.tiff';
            file_put_contents($source, $bytes);

            $process = new Process(['tiff2pdf', '-o', $workdir.'/out.pdf', $source]);
            $process->setTimeout(120);
            $process->run();

            $output = $workdir.'/out.pdf';
            if (! $process->isSuccessful() || ! is_file($output)) {
                return null;
            }

            $pdf = file_get_contents($output);

            return $pdf === false || $pdf === '' ? null : $pdf;
        } finally {
            $this->removeDir($workdir);
        }
    }

    /**
     * @return string fresh empty working directory
     */
    private function workdir(): string
    {
        $dir = sys_get_temp_dir().'/vl-convert-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create conversion workdir.');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
