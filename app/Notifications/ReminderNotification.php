<?php

namespace App\Notifications;

use App\Models\ReminderDelivery;
use Illuminate\Notifications\Notification;

class ReminderNotification extends Notification
{
    public function __construct(
        public ReminderDelivery $delivery,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $payload = $this->delivery->payload;

        return [
            'delivery_id' => (string) $this->delivery->getKey(),
            'item_id' => (string) $this->delivery->item_id,
            'matter_id' => (string) $this->delivery->matter_id,
            'item_title' => $payload['item_title'] ?? null,
            'matter_title' => $payload['matter_title'] ?? null,
            'rule_label' => $payload['rule_label'] ?? null,
            'kind' => $this->delivery->kind,
            'channel' => $this->delivery->channel,
        ];
    }
}
