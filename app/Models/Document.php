<?php

namespace App\Models;

use App\Casts\PostgresTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The document object (007). One row per logical document, owned by
 * exactly one matter (DOC-13) and one organization (tenant boundary).
 * Versions hang off document_versions; current_version_id points at the
 * latest. Soft delete = trash (DOC-11); hard delete is gated by T-09.
 *
 * @property list<string> $tags
 */
class Document extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Mirrors the documents_kind_check constraint.
     *
     * @var list<string>
     */
    public const KINDS = ['uploaded', 'authored', 'generated'];

    /**
     * Mirrors the documents_status_check constraint. Doc-level rollup;
     * scanning/extracting stages live per-version (document_versions).
     *
     * @var list<string>
     */
    public const STATUSES = ['processing', 'ready', 'quarantined', 'trash'];

    /**
     * Mirrors the documents_metadata_status_check constraint (DOC-04).
     *
     * @var list<string>
     */
    public const METADATA_STATUSES = ['ok', 'partial'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'folder_id',
        'title',
        'description',
        'tags',
        'kind',
        'current_version_id',
        'status',
        'metadata_status',
        'retention_flagged_at',
        'created_by',
        'template_id',
        'template_version',
        'draft_content',
        'draft_updated_at',
    ];

    /**
     * Touch contract (006 T-01 pattern): the matter's updated_at drives the
     * matter list's updated-since filter and last-activity summaries, so
     * document mutations bump it.
     *
     * @var list<string>
     */
    protected $touches = ['matter'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // text[] column: Laravel's 'array' cast writes JSON, which
            // Postgres rejects — the custom cast speaks array literals.
            'tags' => PostgresTextArray::class,
            'retention_flagged_at' => 'datetime',
            'draft_updated_at' => 'datetime',
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
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * Source template for kind=generated documents (007 T-04, stationery).
     * Null for uploaded/authored documents.
     *
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderBy('version_number');
    }

    /**
     * @return HasMany<DocumentShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class, 'document_id');
    }

    /**
     * @return HasMany<DocumentGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(DocumentGrant::class, 'document_id');
    }
}
