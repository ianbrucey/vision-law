<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retention policy (007 T-09, DOC-27; table + interval column from T-01).
 *
 * Admin-authored rules per document category (+ optional matter type):
 * retention period, trigger (matter_close / document_date / fixed_date),
 * disposition (destroy / review / archive), and legal basis.
 *
 * Policies are VERSIONED: an edit inserts a new row with the same
 * (org_id, name) and an incremented version; old rows are retained
 * (status 'superseded' once a newer version is activated). Exactly one
 * row per (org_id, name) carries status 'active' — the nightly
 * evaluation only reads active rows.
 *
 * retention_period is a native Postgres interval (e.g. '7 years'),
 * added raw by the T-01 migration; it is written through a validated
 * interval literal (see RetentionService) and read back as a string.
 *
 * @property string $retention_period Postgres interval, e.g. "7 years"
 * @property CarbonInterface|null $fixed_date
 */
class RetentionPolicy extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    public const TRIGGERS = ['matter_close', 'document_date', 'fixed_date'];

    /**
     * @var list<string>
     */
    public const DISPOSITIONS = ['destroy', 'review', 'archive'];

    /**
     * @var list<string>
     */
    public const STATUSES = ['draft', 'active', 'superseded'];

    /**
     * @var list<string>
     */
    public const PERIOD_UNITS = ['days', 'months', 'years'];

    /**
     * Suggested document categories (free text is allowed; policies match
     * documents.category exactly).
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'correspondence',
        'pleading',
        'discovery',
        'contract',
        'administrative',
        'financial',
        'other',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'name',
        'category',
        'matter_type',
        'trigger',
        'disposition',
        'legal_basis',
        'version',
        'status',
        'fixed_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fixed_date' => 'datetime',
            'version' => 'integer',
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
     * @return HasMany<RetentionFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(RetentionFlag::class, 'policy_id');
    }

    /**
     * @param  Builder<RetentionPolicy>  $query
     * @return Builder<RetentionPolicy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * All versions of this policy line, newest first.
     *
     * @return HasMany<RetentionPolicy, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'name', 'name')
            ->where('org_id', $this->org_id)
            ->orderByDesc('version');
    }

    /**
     * Build a validated Postgres interval string (e.g. "7 years") from
     * UI input. The service writes it via a parameterized
     * CAST(? AS interval) — never interpolated into SQL.
     *
     * @throws \InvalidArgumentException on bad amount/unit
     */
    public static function intervalString(int $amount, string $unit): string
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('Retention period must be at least 1.');
        }

        if (! in_array($unit, self::PERIOD_UNITS, true)) {
            throw new \InvalidArgumentException('Unknown retention period unit.');
        }

        return "{$amount} {$unit}";
    }

    /**
     * Display label for the stored interval, e.g. "7 years".
     */
    public function periodLabel(): string
    {
        return trim((string) ($this->getAttribute('retention_period') ?? ''));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
