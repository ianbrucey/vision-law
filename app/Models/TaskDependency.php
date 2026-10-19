<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $org_id
 * @property string $matter_id
 * @property string $predecessor_item_id
 * @property string $successor_item_id
 */
class TaskDependency extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'predecessor_item_id',
        'successor_item_id',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'matter_id');
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'predecessor_item_id');
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'successor_item_id');
    }
}
