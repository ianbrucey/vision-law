<?php

namespace App\Notifications;

use App\Models\CalendarItem;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    public function __construct(
        public CalendarItem $item,
        public ?string $previousAssigneeId = null,
        public ?string $newAssigneeId = null,
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
        return [
            'item_id' => (string) $this->item->getKey(),
            'matter_id' => (string) $this->item->matter_id,
            'title' => $this->item->title,
            'previous_assignee_id' => $this->previousAssigneeId,
            'new_assignee_id' => $this->newAssigneeId,
        ];
    }
}
