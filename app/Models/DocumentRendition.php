<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generated rendition of a document version (007 T-04). The editor
 * publishes authored documents as structured HTML plus a generated PDF
 * rendition; the rendition hangs off the version so every version keeps
 * its own PDF. One row per (version, kind).
 */
class DocumentRendition extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'version_id',
        'kind',
        'blob_id',
    ];

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<DocumentBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(DocumentBlob::class, 'blob_id');
    }
}
