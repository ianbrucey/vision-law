<?php

namespace App\Services\Calendaring\Channels;

use App\Models\ReminderDelivery;
use App\Models\User;
use App\Notifications\ReminderNotification;
use Illuminate\Support\Facades\DB;

class InAppChannel implements ReminderChannel
{
    public function send(ReminderDelivery $delivery): string
    {
        // Dedupe: a notification already carrying this delivery id is
        // the delivered message — return its id, never create another.
        $existing = $this->existingNotificationId($delivery);
        if ($existing !== null) {
            return $existing;
        }

        $recipient = User::query()->whereKey($delivery->recipient_user_id)->firstOrFail();
        $recipient->notify(new ReminderNotification($delivery));

        return $this->existingNotificationId($delivery) ?? (string) $delivery->getKey();
    }

    private function existingNotificationId(ReminderDelivery $delivery): ?string
    {
        $row = DB::table('notifications')
            ->where('type', ReminderNotification::class)
            ->where('notifiable_id', $delivery->recipient_user_id)
            ->whereRaw("data::jsonb ->> 'delivery_id' = ?", [(string) $delivery->getKey()])
            ->first();

        return $row !== null ? (string) $row->id : null;
    }
}
