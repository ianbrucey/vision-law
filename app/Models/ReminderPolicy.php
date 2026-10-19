<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $org_id
 * @property string $matter_id
 * @property string $item_id
 * @property int $version
 * @property bool $active
 * @property array<int, mixed> $rules
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ReminderPolicy extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'item_id',
        'version',
        'active',
        'rules',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'active' => 'boolean',
            'rules' => 'array',
        ];
    }

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
    public function item(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ReminderDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class, 'policy_id');
    }
}
