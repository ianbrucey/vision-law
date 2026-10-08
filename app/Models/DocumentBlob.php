<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content-addressed storage row (007, DOC-01/DOC-18). One row per unique
 * byte sequence; dedupe key is the SHA-256 of the exact bytes.
 *
 * refcount = number of document_versions referencing this blob. Only the
 * DocumentStore implementation mutates refcount; deletes happen only when
 * refcount reaches 0.
 */
class DocumentBlob extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sha256',
        'size',
        'mime_sniffed',
        'storage_path',
        'refcount',
        'quarantined',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'refcount' => 'integer',
            'quarantined' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'blob_id');
    }
}
