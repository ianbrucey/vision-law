<?php

namespace App\Console\Commands;

use App\Services\DocumentFilingService;
use Illuminate\Console\Command;

/**
 * Scheduled hard delete of the document trash (007 T-05, DOC-11).
 *
 * Destroys documents trashed at least
 * DocumentFilingService::TRASH_RETENTION_DAYS ago. Documents under an
 * active legal hold are skipped — the hold wins, and each skip is audited
 * as document.destroy.denied (DOC-28). Wired daily in routes/console.php.
 */
class PurgeDocumentTrash extends Command
{
    /**
     * @var string
     */
    protected $signature = 'documents:purge-trash';

    /**
     * @var string
     */
    protected $description = 'Hard-delete documents trashed 30+ days ago, skipping legal holds';

    public function handle(): int
    {
        $result = DocumentFilingService::purgeTrash();

        $this->info(sprintf(
            'Document trash purge complete: %d purged, %d held (skipped).',
            $result['purged'],
            $result['held']
        ));

        return self::SUCCESS;
    }
}
