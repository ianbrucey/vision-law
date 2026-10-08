<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retention flag (007 T-09, DOC-28).
 *
 * Marks a document as past its retention threshold. policy_id is null
 * for manual flags; flagged_by records the human for manual flags (null
 * for nightly auto-flagging). Rows are deleted when a disposition is
 * executed or the document is hard-deleted.
 *
 * @property CarbonInterface $flagged_at
 */
class RetentionFlag extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'policy_id',
        'reason',
        'flagged_by',
        'flagged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @return BelongsTo<RetentionPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}
