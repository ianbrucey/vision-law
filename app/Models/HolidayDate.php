<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One holiday inside a versioned HolidayCalendar pack (009-D05).
 *
 * @property string $id
 * @property string $calendar_id
 * @property CarbonInterface $date
 * @property string $name
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class HolidayDate extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_id',
        'date',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<HolidayCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'calendar_id');
    }
}
