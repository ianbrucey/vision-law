<?php

namespace App\Console\Commands;

use App\Mail\ScanStalledAlertMail;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Services\AuditLogger;
use App\Services\ChunkedUploadService;
use App\Services\DocumentIngestService;
use App\Services\DocumentStore;
use App\Services\DocumentStoreException;
use App\Services\MalwareScanner;
use App\Services\ScanStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Upload-pipeline sweeper (007 T-02, C-02/C-03).
 *
 * 1. Garbage-collect chunked-upload sessions idle for 24h (007-D05).
 * 2. Re-attempt malware scans for versions stuck in `scanning` past the
 *    alert threshold: clean → document flips to `ready` (the version row
 *    itself is immutable, 007-D06, and keeps its truthful `scanning`
 *    record); infected → quarantine + admin notification; still
 *    unreachable → queue the ops alert exactly once.
 */
class SweepStaleUploadScans extends Command
{
    protected $signature = 'documents:sweep-stale-scans';

    protected $description = 'Expire idle chunked-upload sessions and retry stalled malware scans.';

    public function handle(
        ChunkedUploadService $uploads,
        DocumentIngestService $ingest,
        MalwareScanner $scanner,
        DocumentStore $store,
    ): int {
        $expired = $uploads->sweepExpired();
        $this->info("Expired {$expired} idle upload session(s).");

        $alertAfterMinutes = (int) config('document.clamav.alert_after_minutes', 5);

        $stuck = DocumentVersion::query()
            ->where('processing_status', 'scanning')
            ->where('created_at', '<=', now()->subMinutes($alertAfterMinutes))
            ->with(['document', 'blob'])
            ->get();

        $recovered = 0;
        $quarantined = 0;
        $alerted = 0;

        foreach ($stuck as $version) {
            $document = $version->document;
            $blob = $version->blob;

            if ($document === null || $blob === null || $blob->quarantined) {
                continue;
            }

            try {
                $stream = $store->get($blob);
            } catch (DocumentStoreException) {
                continue;
            }

            $result = $scanner->scanStream($stream);
            $matter = $document->matter;

            if ($matter === null) {
                continue;
            }

            if ($result->status === ScanStatus::CLEAN) {
                if ($document->status === 'processing') {
                    $document->update(['status' => 'ready']);
                }

                AuditLogger::log('document.scanned', null, [
                    'document_id' => (string) $document->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'blob_id' => (string) $blob->getKey(),
                    'verdict' => 'clean',
                    'late' => true,
                ], $matter);

                $recovered++;
            } elseif ($result->status === ScanStatus::INFECTED) {
                $store->quarantine($blob);
                $document->update(['status' => 'quarantined']);

                AuditLogger::log('document.quarantined', null, [
                    'document_id' => (string) $document->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'blob_id' => (string) $blob->getKey(),
                    'signature' => $result->signature,
                    'late' => true,
                ], $matter);

                $ingest->notifyQuarantine($matter, null, $document, $result->signature);

                $quarantined++;
            } else {
                $alreadyAlerted = AuditEvent::query()
                    ->where('event', 'document.scan.delayed')
                    ->where('payload->version_id', (string) $version->getKey())
                    ->exists();

                if ($alreadyAlerted) {
                    continue;
                }

                $stuckMinutes = (int) $version->created_at->diffInMinutes(now());

                Mail::to((string) config('document.ops_alert_email'))->queue(new ScanStalledAlertMail(
                    documentTitle: (string) $document->title,
                    matterNumber: (string) $matter->matter_number,
                    versionId: (string) $version->getKey(),
                    stuckMinutes: $stuckMinutes,
                    socketPath: (string) config('document.clamav.socket'),
                ));

                AuditLogger::log('document.scan.delayed', null, [
                    'document_id' => (string) $document->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'stuck_minutes' => $stuckMinutes,
                ], $matter);

                $alerted++;
            }
        }

        $this->info("Recovered {$recovered}, quarantined {$quarantined}, ops alerts queued {$alerted}.");

        return self::SUCCESS;
    }
}
