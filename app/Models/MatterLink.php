<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatterLink extends Model
{
    use HasUuids;

    /**
     * Mirrors the matter_links_link_type_check constraint.
     *
     * @var list<string>
     */
    public const TYPES = [
        'same_client',
        'consolidated',
        'appeal',
        'companion',
        'other',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'related_matter_id',
        'link_type',
        'note',
    ];

    /**
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
     * Canonical low side of the pair (service-enforced
     * matter_id < related_matter_id, T-03).
     *
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'matter_id');
    }

    /**
     * Canonical high side of the pair.
     *
     * @return BelongsTo<Matter, $this>
     */
    public function relatedMatter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'related_matter_id');
    }
}
