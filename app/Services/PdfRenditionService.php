<?php

namespace App\Services;

use App\Models\DocumentBlob;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Generated PDF renditions (007 T-04, DOC-09/10). Converts structured HTML
 * to PDF via LibreOffice headless (provisioned natively per 007-D02) and
 * stores the bytes through the DocumentStore — controllers never touch
 * storage directly (architecture door, 007-D01).
 */
class PdfRenditionService
{
    public function __construct(private readonly DocumentStore $store) {}

    /**
     * Render $html to PDF and store it as a blob (MIME text application/pdf).
     *
     * @throws PdfRenditionException when the conversion fails — the caller
     *                               treats this as fatal so a published version always carries its
     *                               promised PDF rendition.
     */
    public function fromHtml(string $html): DocumentBlob
    {
        $workDir = sys_get_temp_dir().'/vl-rendition-'.bin2hex(random_bytes(8));
        File::makeDirectory($workDir, 0700, true);

        try {
            $source = $workDir.'/source.html';
            File::put($source, $this->wrapHtml($html));

            // Isolated user profile per run: concurrent soffice processes
            // share a lock dir otherwise.
            $profile = $workDir.'/profile';
            File::makeDirectory($profile, 0700, true);

            $binary = (string) config('document.libreoffice.binary', 'soffice');

            $process = new Process([
                $binary,
                '--headless',
                '--nologo',
                '--nolockcheck',
                '-env:UserInstallation=file://'.$profile,
                '--convert-to',
                'pdf',
                '--outdir',
                $workDir,
                $source,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new PdfRenditionException(
                    'LibreOffice conversion failed: '.trim($process->getErrorOutput())
                );
            }

            $pdf = $workDir.'/source.pdf';

            if (! is_file($pdf)) {
                throw new PdfRenditionException('LibreOffice produced no PDF output.');
            }

            return $this->store->put((string) File::get($pdf), ['mime' => 'application/pdf']);
        } catch (ProcessFailedException $e) {
            throw new PdfRenditionException('LibreOffice conversion failed: '.$e->getMessage(), 0, $e);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Wrap the authored fragment in a minimal print-friendly document so
     * the PDF has sane margins and a serif body.
     */
    private function wrapHtml(string $html): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="utf-8">
            <style>
                body { font-family: serif; font-size: 12pt; line-height: 1.5; margin: 1in; }
                table { border-collapse: collapse; width: 100%; }
                td, th { border: 1px solid #000; padding: 4pt 6pt; }
                .page-break { page-break-before: always; }
            </style>
            </head><body>{$html}</body></html>
            HTML;
    }
}
