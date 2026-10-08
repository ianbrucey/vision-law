<?php

namespace App\Models;

use App\Services\AccessControl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
     * "My Matters" scoping (03-contract.md; C-08, C-10): the org's matters
     * the actor may see — every org matter for org_admin, otherwise only
     * matters with a valid (unexpired, recognized-role) direct or team
     * grant. Applied BEFORE pagination so ungranted matters are excluded
     * entirely, never merely hidden on later pages.
     *
     * Mirrors the T-02 index scoping in MatterController@index (kept as-is;
     * this scope is the reusable form for search and future callers).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAccessibleBy(Builder $query, User $actor): Builder
    {
        $query->where('matters.org_id', $actor->org_id);

        if ($actor->hasRole('org_admin')) {
            return $query;
        }

        $actorId = $actor->getKey();
        $knownRoles = array_keys(AccessControl::ROLE_RANK);

        return $query->where(function ($scope) use ($actorId, $knownRoles): void {
            $scope->whereExists(function ($exists) use ($actorId, $knownRoles): void {
                $exists->selectRaw('1')
                    ->from('matter_grants')
                    ->whereColumn('matter_grants.matter_id', 'matters.id')
                    ->where('matter_grants.user_id', $actorId)
                    ->whereIn('matter_grants.role', $knownRoles)
                    ->where(function ($valid): void {
                        $valid->whereNull('matter_grants.expires_at')
                            ->orWhere('matter_grants.expires_at', '>', now());
                    });
            })->orWhereExists(function ($exists) use ($actorId, $knownRoles): void {
                $exists->selectRaw('1')
                    ->from('matter_grants')
                    ->join('team_user', 'team_user.team_id', '=', 'matter_grants.team_id')
                    ->whereColumn('matter_grants.matter_id', 'matters.id')
                    ->where('team_user.user_id', $actorId)
                    ->whereIn('matter_grants.role', $knownRoles)
                    ->where(function ($valid): void {
                        $valid->whereNull('matter_grants.expires_at')
                            ->orWhere('matter_grants.expires_at', '>', now());
                    });
            });
        });
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
