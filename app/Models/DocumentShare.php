<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * External secure link (007, DOC-25). Only the SHA-256 token_hash is
 * stored — the plaintext token is shown once at creation and never again.
 * version_id pins the shared version; revocation is instant.
 *
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $revoked_at
 */
class DocumentShare extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'version_id',
        'token_hash',
        'expires_at',
        'password_hash',
        'allow_download',
        'revoked_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'allow_download' => 'boolean',
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
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A share is usable only while unrevoked and unexpired (contract
     * §Sharing: expired/revoked render the identical 404).
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
