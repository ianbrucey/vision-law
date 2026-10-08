<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legal hold (007, DOC-28; table + migration from T-01).
 *
 * A hold scoped to a matter or a document blocks hard delete (423),
 * version purge, and matter archival until released. T-05 reads this
 * table to gate the trash-purge path; T-09 owns hold placement/release
 * services and may extend this model.
 */
class LegalHold extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'document_id',
        'reason',
        'created_by',
        'released_at',
        'released_by',
        'release_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }

    /**
     * Only unreleased holds block destruction.
     *
     * @param  Builder<LegalHold>  $query
     * @return Builder<LegalHold>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'matter_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
