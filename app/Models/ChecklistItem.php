<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $item_id
 * @property string $label
 * @property bool $required
 * @property bool $done
 * @property string|null $done_by
 * @property CarbonInterface|null $done_at
 * @property int $position
 */
class ChecklistItem extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'item_id',
        'label',
        'required',
        'done',
        'done_by',
        'done_at',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'done' => 'boolean',
            'done_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
