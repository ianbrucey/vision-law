<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemAttendee extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'item_id',
        'user_id',
        'email',
        'name',
    ];

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
