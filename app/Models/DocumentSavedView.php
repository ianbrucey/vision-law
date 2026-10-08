<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user saved document-list view (007 T-05, C-09 list half).
 *
 * A named bundle of list filters (see DocumentQueryService::FILTER_KEYS)
 * the user can re-apply with one click. Scoped to the owning user —
 * never shared, never cross-user readable.
 *
 * @property array<string, mixed> $filters
 */
class DocumentSavedView extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'filters',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
