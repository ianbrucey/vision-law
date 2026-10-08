<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-matter folder tree (007, DOC-11). Delete empty-only is enforced in
 * the service layer (T-05), not the database.
 */
class DocumentFolder extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'parent_id',
        'name',
    ];

    /**
     * Touch contract (006 T-01 pattern): folder moves bump the matter's
     * updated_at for last-activity summaries.
     *
     * @var list<string>
     */
    protected $touches = ['matter'];

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
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<DocumentFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }
}
