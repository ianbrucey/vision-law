<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned holiday calendar pack (009-D05, T-11). One row per
 * (key, version); rows are never edited after load — a corrected or
 * extended pack is a new version. All v1 packs are DEMONSTRATION
 * data (009-D03): nothing here asserts legal correctness.
 *
 * @property string $id
 * @property string $key
 * @property string $version
 * @property string $label
 * @property bool $demonstration
 * @property string|null $owner_label
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class HolidayCalendar extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'version',
        'label',
        'demonstration',
        'owner_label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demonstration' => 'boolean',
        ];
    }

    /**
     * @return HasMany<HolidayDate, $this>
     */
    public function dates(): HasMany
    {
        return $this->hasMany(HolidayDate::class, 'calendar_id')->orderBy('date');
    }
}
