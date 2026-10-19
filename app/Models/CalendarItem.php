<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $org_id
 * @property string $matter_id
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property string $visibility
 * @property string $status
 * @property string|null $task_status
 * @property string|null $classification
 * @property string|null $jurisdiction
 * @property CarbonInterface|null $due_date
 * @property string|null $cutoff_time
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $all_day
 * @property string $timezone
 * @property string|null $location
 * @property string|null $meeting_url
 * @property string|null $assignee_id
 * @property string|null $escalation_owner_id
 * @property string|null $reviewer_id
 * @property int $version
 * @property string|null $supersedes_id
 * @property string|null $superseded_by_id
 * @property string $source
 * @property string|null $template_version_id
 * @property string|null $rule_key
 * @property array<string, mixed>|null $inputs
 * @property array<string, mixed>|null $calc_trace
 * @property string|null $series_id
 * @property CarbonInterface|null $occurrence_date
 * @property array<string, mixed>|null $recurrence_rule
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CalendarItem extends Model
{
    use HasUuids;

    /** @var list<string> */
    public const TYPES = ['deadline', 'event', 'task'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'confirmed', 'at_risk', 'completed', 'superseded', 'canceled'];

    /** @var list<string> */
    public const TASK_STATUSES = ['not_started', 'in_progress', 'blocked', 'in_review', 'completed', 'canceled'];

    /** @var list<string> */
    public const CLASSIFICATIONS = ['hard', 'soft', 'internal'];

    /** @var list<string> */
    public const VISIBILITIES = ['matter', 'restricted_audience'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'type',
        'title',
        'description',
        'visibility',
        'status',
        'task_status',
        'classification',
        'jurisdiction',
        'due_date',
        'cutoff_time',
        'starts_at',
        'ends_at',
        'all_day',
        'timezone',
        'location',
        'meeting_url',
        'assignee_id',
        'escalation_owner_id',
        'reviewer_id',
        'version',
        'supersedes_id',
        'superseded_by_id',
        'source',
        'template_version_id',
        'rule_key',
        'inputs',
        'calc_trace',
        'series_id',
        'occurrence_date',
        'recurrence_rule',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'occurrence_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'version' => 'integer',
            'inputs' => 'array',
            'calc_trace' => 'array',
            'recurrence_rule' => 'array',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMatter(Builder $query, Matter|string $matter): Builder
    {
        $id = $matter instanceof Matter ? (string) $matter->getKey() : $matter;

        return $query->where('matter_id', $id);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Agenda-friendly: ordered by due/starts/occurrence then title.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAgendaOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('coalesce(due_date, occurrence_date, starts_at::date) asc nulls last')
            ->orderBy('starts_at')
            ->orderBy('title')
            ->orderBy('id');
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function escalationOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ItemAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(ItemAttendee::class, 'item_id');
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
