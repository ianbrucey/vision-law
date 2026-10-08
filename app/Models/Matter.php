<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The matter is the root container and authorization boundary
 * (PRODUCT_BLUEPRINT §4). Lifecycle: INTAKE → ACTIVE → DISCOVERY →
 * PRE_TRIAL → TRIAL_SETTLEMENT → CLOSED → RETENTION_HOLD → DISPOSITION.
 *
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $deleted_at
 */
class Matter extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Mirrors the matters_lifecycle_state_check constraint.
     *
     * @var list<string>
     */
    public const LIFECYCLE_STATES = [
        'INTAKE',
        'ACTIVE',
        'DISCOVERY',
        'PRE_TRIAL',
        'TRIAL_SETTLEMENT',
        'CLOSED',
        'RETENTION_HOLD',
        'DISPOSITION',
    ];

    /**
     * Mirrors the matters_matter_type_check constraint.
     *
     * @var list<string>
     */
    public const MATTER_TYPES = [
        'litigation',
        'transactional',
        'regulatory',
        'employment',
        'real_estate',
        'estate_planning',
        'other',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_number',
        'title',
        'lifecycle_state',
        'matter_type',
        'description',
        'client_name',
        'closed_at',
        'template_id',
        'template_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'template_version' => 'integer',
        ];
    }

    /**
     * Touch contract (006 T-01): matter-owned children (parties, comments,
     * links, document-log rows) declare `$touches = ['matter']`, so any
     * child mutation bumps the matter's updated_at. That timestamp drives
     * the T-04 status summary's "last activity" and the matter list's
     * updated-since filter. Comment reads are telemetry and are
     * deliberately excluded (see MatterCommentRead).
     *
     * This is the parent-side hook point for future touch-related
     * invariants; the propagation itself lives on the child models.
     */
    protected static function booted(): void
    {
        //
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return HasMany<MatterGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(MatterGrant::class, 'matter_id');
    }

    /**
     * Users assigned to this matter, via matter_grants (team grants carry
     * no user_id and are excluded).
     *
     * @return HasManyThrough<User, MatterGrant, $this>
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            MatterGrant::class,
            'matter_id',
            'id',
            'id',
            'user_id'
        );
    }

    /**
     * @return HasMany<MatterParty, $this>
     */
    public function parties(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id');
    }

    /**
     * @return HasMany<MatterComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(MatterComment::class, 'matter_id');
    }

    /**
     * @return HasMany<MatterLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(MatterLink::class, 'matter_id');
    }

    /**
     * @return HasMany<MatterDocumentLog, $this>
     */
    public function documentLogs(): HasMany
    {
        return $this->hasMany(MatterDocumentLog::class, 'matter_id');
    }

    /**
     * @return HasMany<MatterCommentRead, $this>
     */
    public function commentReads(): HasMany
    {
        return $this->hasMany(MatterCommentRead::class, 'matter_id');
    }
}
