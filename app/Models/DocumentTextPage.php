<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-page extracted/OCR text for one immutable document version
 * (spec 007 T-06, DOC-15/16/17).
 *
 * Written idempotently per version by the extraction/OCR pipeline
 * (UNIQUE on (version_id, page_number); re-runs are no-ops).
 *
 * - text: the page's extracted text (native extraction or OCR).
 * - text_tsv: tsvector maintained by the trg_document_text_pages_tsv
 *   trigger; GIN-indexed for full-text search.
 * - ocr_confidence: mean per-word Tesseract confidence for the page,
 *   0..1; null for natively-extracted pages.
 * - ocr_words: per-word confidences [{t: word, c: 0..1}] (DOC-16).
 */
class DocumentTextPage extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'version_id',
        'page_number',
        'text',
        'ocr_confidence',
        'ocr_words',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'ocr_confidence' => 'decimal:4',
            'ocr_words' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }
}
