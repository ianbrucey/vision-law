<?php

namespace App\Services\Calendaring\Channels;

use App\Models\ReminderDelivery;

/**
 * Channel contract (009 T-07). Implementations are resolved through
 * the container keys calendaring.channel.in_app /
 * calendaring.channel.email so tests can substitute doubles.
 *
 * Returns a provider reference for the ledger.
 */
interface ReminderChannel
{
    public function send(ReminderDelivery $delivery): string;
}
