<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatterParty extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Mirrors the matter_parties_party_type_check constraint.
     *
     * @var list<string>
     */
    public const TYPES = [
        'client',
        'opposing_party',
        'opposing_counsel',
        'witness',
        'expert',
        'court',
        'other',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'party_type',
        'name',
        'role_description',
        'email',
        'phone',
        'address',
        'user_id',
    ];

    /**
     * Party changes bump the matter's updated_at (drives the T-04
     * status summary's "last activity" and the updated-since filter).
     *
     * @var list<string>
     */
    protected $touches = ['matter'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
