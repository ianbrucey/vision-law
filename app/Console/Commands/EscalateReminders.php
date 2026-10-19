<?php

namespace App\Console\Commands;

use App\Models\MatterGrant;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Calendaring\DeliveryService;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Console\Command;

/**
 * Escalation sweep (009-D17, T-08).
 *
 * For each item whose mandatory reminder was sent and remains
 * unacknowledged once now reaches the item's base instant (due),
 * create ONE escalation delivery (kind=escalation, criticality
 * mandatory, channel in_app) to the item's escalation owner —
 * falling back to a matter owner via grants — and deliver it.
 * Escalations are never digested and never quieted (mandatory).
 * The idempotency key is per item+escalation, so re-runs create
 * nothing new.
 */
class EscalateReminders extends Command
{
    protected $signature = 'reminders:escalate';

    protected $description = 'Escalate unacknowledged mandatory reminders past their due instant.';

    public function handle(DeliveryService $service): int
    {
        $candidates = ReminderDelivery::query()
            ->where('kind', 'reminder')
            ->where('status', 'sent')
            ->where('payload->criticality', 'mandatory')
            ->orderBy('scheduled_for')
            ->get();

        $created = 0;

        foreach ($candidates as $candidate) {
            $item = $candidate->item;
            if ($item === null) {
                continue;
            }

            $base = ReminderPlanner::baseInstant($item);
            if ($base === null || now()->lessThan($base)) {
                continue;
            }

            $already = ReminderDelivery::query()
                ->where('item_id', $item->getKey())
                ->where('kind', 'escalation')
                ->exists();
            if ($already) {
                continue;
            }

            $recipient = $this->escalationRecipient($candidate);
            if ($recipient === null) {
                continue;
            }

            $key = hash('sha256', implode('|', ['escalation', (string) $item->getKey()]));

            $escalation = ReminderDelivery::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'org_id' => (string) $item->org_id,
                    'matter_id' => (string) $item->matter_id,
                    'item_id' => (string) $item->getKey(),
                    'policy_id' => $candidate->policy_id,
                    'kind' => 'escalation',
                    'recipient_user_id' => (string) $recipient->getKey(),
                    'recipient_email' => $recipient->email,
                    'channel' => 'in_app',
                    'scheduled_for' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'payload' => [
                        'item_title' => $item->title,
                        'matter_title' => $item->matter !== null ? (string) $item->matter->title : null,
                        'criticality' => 'mandatory',
                        'rule_label' => 'Escalation',
                        'escalated_from_delivery_id' => (string) $candidate->getKey(),
                        'item_instant' => $base->toIso8601String(),
                    ],
                ]
            );

            if (! $escalation->wasRecentlyCreated) {
                continue;
            }

            $created++;

            AuditLogger::log('calendar.reminder.escalated', null, [
                'item_id' => (string) $item->getKey(),
                'delivery_id' => (string) $escalation->getKey(),
                'source_delivery_id' => (string) $candidate->getKey(),
                'recipient_user_id' => (string) $recipient->getKey(),
            ], $item->matter);

            if ($escalation->status === 'pending') {
                $service->deliver($escalation);
            }
        }

        $this->info("Created {$created} escalation deliverie(s).");

        return self::SUCCESS;
    }

    private function escalationRecipient(ReminderDelivery $candidate): ?User
    {
        $item = $candidate->item;
        if ($item === null) {
            return null;
        }

        if ($item->escalation_owner_id !== null) {
            $owner = User::query()->whereKey($item->escalation_owner_id)->first();
            if ($owner !== null && $owner->deactivated_at === null) {
                return $owner;
            }
        }

        // Fallback: a matter owner via grants.
        $grant = MatterGrant::query()
            ->where('matter_id', $item->matter_id)
            ->whereIn('role', ['owner', 'matter_owner'])
            ->whereNotNull('user_id')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->first();

        if ($grant === null || $grant->user_id === null) {
            return null;
        }

        $user = User::query()->whereKey($grant->user_id)->first();

        return $user !== null && $user->deactivated_at === null ? $user : null;
    }
}
