<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\ItemAttendee;
use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Immediate notices (009-D17, T-08).
 *
 * Every immediate notice is a ledger delivery (kind=notice, channel
 * in_app, scheduled_for=now) delivered through DeliveryService, so
 * the reminder_deliveries ledger is the single system of record for
 * assignment, confirmation, cancellation and material-date-change
 * notices — there is no side channel writing notifications directly.
 *
 * Confirm / cancel / material-date-change notices are mandatory;
 * assignment notices are likewise immediate, so all notices created
 * here default to mandatory criticality, which bypasses digest and
 * quiet hours (009-D06).
 */
class NoticeService
{
    /**
     * Recipients for item-level notices: the assignee plus every
     * attendee with a user account, de-duplicated.
     *
     * @return list<User>
     */
    public static function recipientsForItem(CalendarItem $item): array
    {
        $ids = [];

        if ($item->assignee_id !== null) {
            $ids[] = (string) $item->assignee_id;
        }

        $attendeeIds = ItemAttendee::query()
            ->where('item_id', $item->getKey())
            ->whereNotNull('user_id')
            ->pluck('user_id');

        foreach ($attendeeIds as $id) {
            $ids[] = (string) $id;
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
     * Create + deliver one notice delivery to each recipient.
     *
     * @param  list<User>  $recipients
     * @param  array<string, mixed>  $extra
     * @return list<ReminderDelivery>
     */
    public static function notifyMany(CalendarItem $item, array $recipients, string $noticeType, array $extra = [], ?User $actor = null, string $criticality = 'mandatory'): array
    {
        $out = [];
        $seen = [];
        foreach ($recipients as $recipient) {
            $id = (string) $recipient->getKey();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $delivery = self::notify($item, $recipient, $noticeType, $extra, $actor, $criticality);
            if ($delivery !== null) {
                $out[] = $delivery;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function notify(CalendarItem $item, User $recipient, string $noticeType, array $extra = [], ?User $actor = null, string $criticality = 'mandatory'): ?ReminderDelivery
    {
        $payload = array_merge([
            'item_title' => $item->title,
            'matter_title' => $item->matter !== null ? (string) $item->matter->title : null,
            'criticality' => $criticality,
            'rule_label' => self::labelFor($noticeType),
            'notice_type' => $noticeType,
            'actor_name' => $actor !== null ? $actor->name : null,
            'actor_id' => $actor !== null ? (string) $actor->getKey() : null,
        ], $extra);

        $delivery = ReminderDelivery::create([
            'org_id' => (string) $item->org_id,
            'matter_id' => (string) $item->matter_id,
            'item_id' => (string) $item->getKey(),
            'policy_id' => null,
            'kind' => 'notice',
            'recipient_user_id' => (string) $recipient->getKey(),
            'recipient_email' => $recipient->email,
            'channel' => 'in_app',
            'idempotency_key' => (string) Str::uuid(),
            'scheduled_for' => now(),
            'status' => 'pending',
            'attempts' => 0,
            'payload' => $payload,
        ]);

        return app(DeliveryService::class)->deliver($delivery);
    }

    private static function labelFor(string $noticeType): string
    {
        return match ($noticeType) {
            'assignment' => 'Task assigned',
            'confirmed' => 'Item confirmed',
            'canceled' => 'Item canceled',
            'material_date_change' => 'Date changed',
            default => ucfirst(str_replace('_', ' ', $noticeType)),
        };
    }
}
