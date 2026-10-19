<?php

namespace App\Exceptions;

use App\Models\CalendarItem;
use RuntimeException;

class CalendarConflictException extends RuntimeException
{
    public function __construct(
        public readonly CalendarItem $current,
        string $message = 'This item was changed by someone else. Review the current version before saving.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'code' => 'conflict',
            'message' => $this->getMessage(),
            'current' => [
                'id' => (string) $this->current->getKey(),
                'title' => $this->current->title,
                'version' => $this->current->version,
                'status' => $this->current->status,
                'due_date' => $this->current->due_date?->toDateString(),
                'updated_at' => $this->current->updated_at->toIso8601String(),
            ],
        ];
    }
}
