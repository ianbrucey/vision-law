<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * Nightly retention evaluation (007 T-09, DOC-27/28/29).
 *
 * Flags documents at retention thresholds (document.retention.flagged)
 * and auto-queues destroy/archive dispositions for flagged documents.
 * Idempotent: documents with an existing flag or an open queue entry
 * are skipped; documents under an active legal hold are flagged but
 * never auto-queued — the hold wins.
 *
 * Wired daily in routes/console.php.
 */
class EvaluateRetention extends Command
{
    /**
     * @var string
     */
    protected $signature = 'retention:evaluate';

    /**
     * @var string
     */
    protected $description = 'Evaluate active retention policies: flag documents and queue dispositions';

    public function handle(): int
    {
        $result = RetentionService::evaluateNightly();

        $this->info(sprintf(
            'Retention evaluation complete: %d active policies, %d documents flagged, %d dispositions queued.',
            $result['policies'],
            $result['flagged'],
            $result['queued']
        ));

        return self::SUCCESS;
    }
}
