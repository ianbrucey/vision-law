<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal document grant (007, DOC-24). Narrower than matter grants;
 * effective permission = max(matter role, grant). team_id is reserved
 * for a future teams model and unused.
 */
class DocumentGrant extends Model
{
    use HasUuids;

    /**
     * Mirrors the document_grants_level_check constraint.
     *
     * @var list<string>
     */
    public const LEVELS = ['viewer', 'commenter', 'editor'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'user_id',
        'team_id',
        'level',
        'created_by',
    ];

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
