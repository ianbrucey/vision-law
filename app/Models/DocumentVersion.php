<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable content version (007, DOC-18/20, 007-D06). Every content change
 * is a new row with a gapless version_number per document; history is
 * never rewritten. DB-level immutability: a BEFORE UPDATE/DELETE trigger
 * (same pattern as 001's audit trigger) raises on any mutation attempt.
 *
 * @property CarbonInterface $created_at
 */
class DocumentVersion extends Model
{
    use HasUuids;

    /**
     * Mirrors the document_versions_processing_status_check constraint.
     *
     * @var list<string>
     */
    public const PROCESSING_STATUSES = ['pending', 'scanning', 'extracting', 'ocr', 'ready', 'failed'];

    /**
     * No updated_at — the version row is immutable.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'version_number',
        'blob_id',
        'change_note',
        'restored_from_version_id',
        'processing_status',
        'page_count',
        'original_filename',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'page_count' => 'integer',
            'created_at' => 'datetime',
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
     * @return BelongsTo<DocumentBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(DocumentBlob::class, 'blob_id');
    }

    /**
     * Per-page extracted/OCR text rows (007 T-06, DOC-15/16/17).
     * Idempotent per version; read by full-text search and T-07 diffs.
     *
     * @return HasMany<DocumentTextPage, $this>
     */
    public function textPages(): HasMany
    {
        return $this->hasMany(DocumentTextPage::class, 'version_id')->orderBy('page_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rollback lineage: the earlier version this row was restored from
     * (007-D06). Null for ordinary content versions.
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'restored_from_version_id');
    }
}
