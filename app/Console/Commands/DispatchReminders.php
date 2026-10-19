<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderDelivery;
use App\Models\CalendarItem;
use App\Models\ReminderDelivery;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Materialize due reminder deliveries and queue them for sending.';

    public function handle(): int
    {
        // Materialize missing deliveries for confirmed items with an
        // active policy (idempotent on the ledger key).
        $items = CalendarItem::query()
            ->where('status', 'confirmed')
            ->whereHas('reminderPolicies', fn ($query) => $query->where('active', true))
            ->get();

        foreach ($items as $item) {
            ReminderPlanner::materialize($item);
        }

        // Reclaim stale claims (claimed > 10 minutes ago -> pending).
        ReminderDelivery::query()
            ->where('status', 'claimed')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending']);

        // Snoozed deliveries whose snooze has expired are due again
        // (T-08): snoozed -> pending, then the normal flow below; a
        // re-send lands them back at sent.
        ReminderDelivery::query()
            ->where('status', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->update(['status' => 'pending', 'snoozed_until' => null]);

        $due = ReminderDelivery::query()
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('scheduled_for')
            ->get();

        foreach ($due as $delivery) {
            SendReminderDelivery::dispatch((string) $delivery->getKey());
        }

        $this->info("Dispatched {$due->count()} reminder deliverie(s).");

        return self::SUCCESS;
    }
}
