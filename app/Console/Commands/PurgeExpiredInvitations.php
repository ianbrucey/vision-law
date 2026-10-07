<?php

namespace App\Console\Commands;

use App\Services\InvitationService;
use Illuminate\Console\Command;

/**
 * Nightly purge of expired invitations (C-05). Only expired, UNACCEPTED
 * invitations are deleted — accepted ones are kept as the record of how
 * the user joined. Registered in the scheduler (routes/console.php).
 */
class PurgeExpiredInvitations extends Command
{
    protected $signature = 'invitations:purge-expired';

    protected $description = 'Delete expired, unaccepted invitations.';

    public function handle(InvitationService $service): int
    {
        $count = $service->purgeExpired();

        $this->info("Purged {$count} expired invitation(s).");

        return self::SUCCESS;
    }
}
