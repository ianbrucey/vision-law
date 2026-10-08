<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Matter comments: markdown body, one-level threading (a reply's parent
 * must itself be top-level — enforced in the service, T-03), 24h author
 * edit window (edited_at), soft delete → tombstone (row and author
 * retained for the audit trail).
 *
 * @property CarbonInterface|null $edited_at
 */
class MatterComment extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'parent_id',
        'author_id',
        'body',
    ];

    /**
     * @var list<string>
     */
    protected $touches = ['matter'];

    /**
     * Microsecond precision (006-D15): the MAT-16 unread comparison
     * (comment created_at vs last_read_at) needs sub-second timestamps;
     * the default 'Y-m-d H:i:s' format would truncate them on write.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
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
     * @return BelongsTo<MatterComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MatterComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
