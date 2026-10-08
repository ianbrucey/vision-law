<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Process;

/**
 * Native Tesseract OCR provider (spec 007 T-06, DOC-16; 007-D02).
 *
 * Renders each PDF page at 300 DPI via pdftoppm, then runs Tesseract
 * with TSV output so per-word confidence scores survive. Words are
 * grouped back into lines (block/par/line) for the page text.
 *
 * Never throws: an unreadable page yields an empty-text result with
 * confidence 0, and the pipeline marks the document `partial`.
 */
final class TesseractOcrProvider implements OcrProvider
{
    public function providerName(): string
    {
        return 'tesseract';
    }

    /**
     * @return array<int, OcrPageResult>
     */
    public function ocrPdf(string $pdfPath, int $pageCount): array
    {
        $results = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $started = microtime(true);
            $image = $this->renderPage($pdfPath, $page);

            try {
                $results[$page] = $image === null
                    ? new OcrPageResult($page, '', 0.0, [], $this->elapsedMs($started))
                    : $this->ocrImage($image, $page);
            } finally {
                if ($image !== null && file_exists($image)) {
                    @unlink($image);
                }
            }
        }

        return $results;
    }

    public function ocrImage(string $imagePath, int $pageNumber): OcrPageResult
    {
        $started = microtime(true);

        try {
            $tsv = $this->runTesseractTsv($imagePath);
        } catch (\Throwable) {
            return new OcrPageResult($pageNumber, '', 0.0, [], $this->elapsedMs($started));
        }

        [$text, $words] = $this->parseTsv($tsv);

        $confidence = count($words) > 0
            ? array_sum(array_column($words, 'c')) / count($words)
            : 0.0;

        return new OcrPageResult(
            $pageNumber,
            $text,
            round(min(1.0, max(0.0, $confidence)), 4),
            $words,
            $this->elapsedMs($started),
        );
    }

    /**
     * Render one PDF page to a temp PNG at the configured DPI.
     */
    private function renderPage(string $pdfPath, int $page): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vl-ocr-');
        if ($tmp === false) {
            return null;
        }
        @unlink($tmp);
        $prefix = $tmp.'-page';

        $dpi = (string) config('document.ocr.dpi', 300);
        $timeout = (int) config('document.ocr.page_timeout_seconds', 120);

        try {
            $this->run(
                ['pdftoppm', '-r', $dpi, '-png', '-f', (string) $page, '-l', (string) $page, $pdfPath, $prefix],
                $timeout
            );
        } catch (\Throwable) {
            return null;
        }

        $png = $prefix.'-'.str_pad((string) $page, 2, '0', STR_PAD_LEFT).'.png';
        if (! file_exists($png)) {
            // pdftoppm zero-pads by page-count width; fall back to a glob.
            $matches = glob($prefix.'-*.png');
            $png = $matches[0] ?? null;
        }

        return is_string($png) && file_exists($png) ? $png : null;
    }

    /**
     * Tesseract TSV output: level,page,block,par,line,word,left,top,
     * width,height,conf,text. Word rows are level 5.
     */
    private function runTesseractTsv(string $imagePath): string
    {
        $lang = (string) config('document.ocr.language', config('document.tesseract.language', 'eng'));
        $timeout = (int) config('document.ocr.page_timeout_seconds', 120);

        return $this->run(
            [(string) config('document.tesseract.binary', 'tesseract'), $imagePath, 'stdout', '-l', $lang, '--psm', '3', 'tsv'],
            $timeout
        );
    }

    /**
     * @return array{0: string, 1: list<array{t: string, c: float}>}
     */
    private function parseTsv(string $tsv): array
    {
        $lines = [];
        $words = [];

        foreach (explode("\n", $tsv) as $row) {
            $cols = explode("\t", rtrim($row));
            if (count($cols) < 12 || $cols[0] !== '5') {
                continue;
            }

            $text = trim($cols[11]);
            if ($text === '') {
                continue;
            }

            $conf = max(0.0, min(100.0, (float) $cols[10])) / 100.0;
            $key = $cols[2].'.'.$cols[3].'.'.$cols[4]; // block.par.line
            $lines[$key][] = $text;
            $words[] = ['t' => $text, 'c' => round($conf, 4)];
        }

        ksort($lines);
        $text = implode("\n", array_map(static fn (array $w): string => implode(' ', $w), $lines));

        return [$text, $words];
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
            throw new \RuntimeException('OCR subprocess failed: '.implode(' ', $command));
        }

        return $process->getOutput();
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
