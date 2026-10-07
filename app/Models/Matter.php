<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matter extends Model
{
    use HasUuids;

    /**
     * Minimal stub per 001-D03: the matter-management feature EXTENDs this
     * table via new migrations — it never edits these columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_number',
        'title',
        'status',
    ];

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
}
