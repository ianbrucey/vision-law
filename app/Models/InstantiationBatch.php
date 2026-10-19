<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One confirmed template instantiation into a matter (009 T-12,
 * 009-D20). Created only by InstantiationService::confirm(), inside
 * the same transaction that creates the generated CalendarItems, so a
 * batch never exists without its full graph. Idempotency lives on
 * the (matter_id, idempotency_key) unique pair: a duplicate confirm
 * returns this row unchanged.
 *
 * `inputs` are the trigger facts the batch was computed from;
 * RecalculationService replaces them when a recalculation is applied
 * (009-D21).
 *
 * @property string $id
 * @property string $org_id
 * @property string $matter_id
 * @property string $template_version_id
 * @property string $idempotency_key
 * @property array<string, mixed> $inputs
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class InstantiationBatch extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'template_version_id',
        'idempotency_key',
        'inputs',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inputs' => 'array',
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
     * @return BelongsTo<DeadlineTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DeadlineTemplateVersion::class, 'template_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every item this batch generated, including superseded history
     * rows (recalculation successors carry the same batch id).
     *
     * @return HasMany<CalendarItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CalendarItem::class, 'instantiation_batch_id');
    }
}
