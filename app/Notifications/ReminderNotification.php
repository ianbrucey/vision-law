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

        $data = [
            'delivery_id' => (string) $this->delivery->getKey(),
            'item_id' => (string) $this->delivery->item_id,
            'matter_id' => (string) $this->delivery->matter_id,
            'item_title' => $payload['item_title'] ?? null,
            'matter_title' => $payload['matter_title'] ?? null,
            'rule_label' => $payload['rule_label'] ?? null,
            'kind' => $this->delivery->kind,
            'channel' => $this->delivery->channel,
        ];

        // Notice context (T-08): assignment old/new assignees, the
        // actor, and before/after snapshots ride in the payload and
        // are surfaced verbatim so the ledger-backed notification
        // carries the same context the old direct notification did.
        foreach (['notice_type', 'actor_name', 'actor_id', 'previous_assignee_id', 'new_assignee_id', 'before', 'after'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        // Digest context (T-08): the held items list.
        if (isset($payload['digest_items']) && is_array($payload['digest_items'])) {
            $data['digest_items'] = $payload['digest_items'];
            $titles = [];
            foreach ($payload['digest_items'] as $entry) {
                if (is_array($entry) && isset($entry['item_title']) && is_string($entry['item_title'])) {
                    $titles[] = $entry['item_title'];
                }
            }
            $data['item_titles'] = $titles;
        } elseif (isset($payload['item_titles']) && is_array($payload['item_titles'])) {
            $data['item_titles'] = $payload['item_titles'];
        }

        // Escalation context (T-08).
        if (isset($payload['escalated_from_delivery_id'])) {
            $data['escalated_from_delivery_id'] = $payload['escalated_from_delivery_id'];
        }

        // Title alias the assignment flow historically used.
        if (($payload['notice_type'] ?? null) === 'assignment') {
            $data['title'] = $payload['item_title'] ?? null;
        }

        return $data;
    }
}
