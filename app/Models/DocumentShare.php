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
 * @property CarbonInterface|null $password_locked_until
 * @property int $failed_password_attempts
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
        'failed_password_attempts',
        'password_locked_until',
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
            'password_locked_until' => 'datetime',
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

    /**
     * 5 wrong passwords lock the link for 15 minutes (007 T-08, DOC-25).
     * A locked link still resolves (its token is valid) and surfaces 429
     * share_locked_out — only expired/revoked/unknown tokens share the
     * identical 404.
     */
    public function isLockedOut(): bool
    {
        return $this->password_locked_until !== null
            && $this->password_locked_until->isFuture();
    }
}
