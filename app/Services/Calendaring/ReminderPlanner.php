<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\MatterGrant;
use App\Models\ReminderDelivery;
use App\Models\ReminderPolicy;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reminder policy planner (009-D15/D16/D23).
 *
 * Materializes ledger rows (ReminderDelivery) from an item's active
 * policy version. Relative rules resolve against the item's current
 * base instant; absolute rules keep their instant. Materialization is
 * idempotent on the unique idempotency key.
 */
class ReminderPlanner
{
    /**
     * The instant against which relative offsets resolve.
     *
     * Timed items: starts_at (already UTC). Date-only items: due_date
     * at cutoff_time (default 17:00) in the item's timezone, to UTC.
     */
    public static function baseInstant(CalendarItem $item): ?Carbon
    {
        if ($item->starts_at !== null) {
            return Carbon::instance($item->starts_at)->setTimezone('UTC');
        }

        if ($item->due_date !== null) {
            $date = $item->due_date->toDateString();
            $cutoff = $item->cutoff_time !== null ? substr((string) $item->cutoff_time, 0, 5) : '17:00';
            $tz = $item->timezone !== '' ? $item->timezone : 'UTC';

            return Carbon::parse($date.' '.$cutoff, $tz)->setTimezone('UTC');
        }

        if ($item->occurrence_date !== null) {
            $tz = $item->timezone !== '' ? $item->timezone : 'UTC';

            return Carbon::parse($item->occurrence_date->toDateString().' 17:00', $tz)->setTimezone('UTC');
        }

        return null;
    }

    /**
     * Resolve recipient specs to live users.
     *
     * Specs: assignee | owner | watchers | attendees |
     * escalation_owner | user:<uuid> (a single spec string or a list).
     *
     * @param  string|array<int, mixed>  $spec
     * @return list<User>
     */
    public static function resolveRecipients(CalendarItem $item, string|array $spec): array
    {
        $specs = is_array($spec) ? $spec : [$spec];
        $ids = [];

        foreach ($specs as $one) {
            if (! is_string($one) || $one === '') {
                continue;
            }

            if ($one === 'assignee') {
                if ($item->assignee_id !== null) {
                    $ids[] = (string) $item->assignee_id;
                }
            } elseif ($one === 'escalation_owner') {
                if ($item->escalation_owner_id !== null) {
                    $ids[] = (string) $item->escalation_owner_id;
                }
            } elseif ($one === 'owner') {
                foreach (self::directGranteeIds($item, ['owner', 'matter_owner']) as $id) {
                    $ids[] = $id;
                }
            } elseif ($one === 'watchers') {
                foreach (self::directGranteeIds($item, array_keys(AccessControl::ROLE_RANK)) as $id) {
                    $ids[] = $id;
                }
            } elseif ($one === 'attendees') {
                $item->loadMissing('attendees');
                foreach ($item->attendees as $attendee) {
                    if ($attendee->user_id !== null) {
                        $ids[] = (string) $attendee->user_id;
                    }
                }
            } elseif (str_starts_with($one, 'user:')) {
                $id = substr($one, 5);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $ids)->get()->keyBy(fn (User $u) => (string) $u->getKey());

        $out = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            if ($user instanceof User && $user->deactivated_at === null) {
                $out[] = $user;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private static function directGranteeIds(CalendarItem $item, array $roles): array
    {
        return array_values(MatterGrant::query()
            ->where('matter_id', $item->matter_id)
            ->whereIn('role', $roles)
            ->whereNotNull('user_id')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all());
    }

    public static function activePolicy(CalendarItem $item): ?ReminderPolicy
    {
        return ReminderPolicy::query()
            ->where('item_id', $item->getKey())
            ->where('active', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Materialize pending deliveries from the item's active policy.
     * No-ops unless the item is confirmed and has an active policy
     * with a resolvable base instant (for relative rules).
     *
     * @return list<ReminderDelivery>
     */
    public static function materialize(CalendarItem $item): array
    {
        if ($item->status !== 'confirmed') {
            return [];
        }

        $policy = self::activePolicy($item);
        if ($policy === null) {
            return [];
        }

        $base = self::baseInstant($item);
        $matterTitle = $item->matter !== null ? (string) $item->matter->title : null;
        $rules = $policy->rules;

        $created = [];

        foreach (array_values($rules) as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $scheduled = self::scheduledFor($rule, $base);
            if ($scheduled === null) {
                continue;
            }

            $recipientsRaw = $rule['recipients'] ?? [];
            /** @var string|array<int, mixed> $recipientsSpec */
            $recipientsSpec = is_array($recipientsRaw) || is_string($recipientsRaw) ? $recipientsRaw : [];
            $recipients = self::resolveRecipients($item, $recipientsSpec);

            $channelsRaw = $rule['channels'] ?? ['in_app'];
            /** @var list<mixed> $channelsList */
            $channelsList = is_array($channelsRaw) ? array_values($channelsRaw) : [$channelsRaw];

            foreach ($recipients as $recipient) {
                foreach ($channelsList as $channelRaw) {
                    $channel = is_string($channelRaw) ? $channelRaw : 'in_app';
                    if (! in_array($channel, ReminderDelivery::CHANNELS, true)) {
                        continue;
                    }

                    $scheduledIso = $scheduled->copy()->setTimezone('UTC')->toIso8601String();
                    $key = hash('sha256', implode('|', [
                        (string) $policy->getKey(),
                        (string) $item->getKey(),
                        (string) $recipient->getKey(),
                        $channel,
                        $scheduledIso,
                        (string) $index,
                    ]));

                    $labelRaw = $rule['label'] ?? null;
                    $criticalityRaw = $rule['criticality'] ?? null;

                    $delivery = ReminderDelivery::firstOrCreate(
                        ['idempotency_key' => $key],
                        [
                            'org_id' => (string) $item->org_id,
                            'matter_id' => (string) $item->matter_id,
                            'item_id' => (string) $item->getKey(),
                            'policy_id' => (string) $policy->getKey(),
                            'kind' => 'reminder',
                            'recipient_user_id' => (string) $recipient->getKey(),
                            'recipient_email' => $recipient->email,
                            'channel' => $channel,
                            'scheduled_for' => $scheduled,
                            'status' => 'pending',
                            'attempts' => 0,
                            'payload' => [
                                'item_title' => $item->title,
                                'matter_title' => $matterTitle,
                                'rule_label' => is_string($labelRaw) ? $labelRaw : null,
                                'criticality' => is_string($criticalityRaw) ? $criticalityRaw : null,
                                'rule_index' => $index,
                                'item_instant' => $base?->toIso8601String(),
                                'scheduled_for' => $scheduledIso,
                            ],
                        ]
                    );

                    $created[] = $delivery;
                }
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function scheduledFor(array $rule, ?Carbon $base): ?Carbon
    {
        if (isset($rule['absolute_at']) && is_string($rule['absolute_at']) && $rule['absolute_at'] !== '') {
            return Carbon::parse($rule['absolute_at'])->setTimezone('UTC');
        }

        if ($base === null) {
            return null;
        }

        $offset = isset($rule['offset_minutes']) ? (int) $rule['offset_minutes'] : 0;

        return $base->copy()->addMinutes($offset);
    }

    /**
     * Supersede every pending delivery for the item. Sent,
     * acknowledged and dead rows are never touched.
     */
    public static function supersedePending(CalendarItem $item): int
    {
        return ReminderDelivery::query()
            ->where('item_id', $item->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'superseded']);
    }

    /**
     * Supersede pending deliveries, then materialize fresh from the
     * current active policy.
     *
     * @return list<ReminderDelivery>
     */
    public static function reschedule(CalendarItem $item): array
    {
        self::supersedePending($item);

        return self::materialize($item);
    }

    /**
     * Create the next policy version for an item, deactivate the
     * prior version, and reschedule pending deliveries.
     *
     * @param  array<int, mixed>  $rules
     */
    public static function setPolicy(CalendarItem $item, array $rules, ?User $actor = null): ReminderPolicy
    {
        return DB::transaction(function () use ($item, $rules, $actor): ReminderPolicy {
            $max = ReminderPolicy::query()->where('item_id', $item->getKey())->max('version');
            $nextVersion = is_numeric($max) ? ((int) $max) + 1 : 1;

            ReminderPolicy::query()
                ->where('item_id', $item->getKey())
                ->where('active', true)
                ->update(['active' => false]);

            $policy = ReminderPolicy::create([
                'org_id' => (string) $item->org_id,
                'matter_id' => (string) $item->matter_id,
                'item_id' => (string) $item->getKey(),
                'version' => $nextVersion,
                'active' => true,
                'rules' => array_values($rules),
                'created_by' => $actor !== null ? (string) $actor->getKey() : null,
            ]);

            if ($actor !== null && $item->matter !== null) {
                AuditLogger::log('calendar.item.updated', $actor, [
                    'item_id' => (string) $item->getKey(),
                    'policy_version' => $nextVersion,
                    'policy_id' => (string) $policy->getKey(),
                    'before' => [],
                    'after' => ['policy_version' => $nextVersion],
                ], $item->matter);
            }

            // New policy version => pending deliveries from the old
            // version are superseded and fresh rows materialized.
            // When there is no actor (fixture / system path), still
            // reschedule so the ledger matches the active policy.
            self::reschedule($item->fresh() ?? $item);

            return $policy;
        });
    }
}
