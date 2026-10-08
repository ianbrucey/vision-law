<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chunked/resumable upload session (007-D05, C-02).
 *
 * `received_chunks` is the resume bitmap (sorted list of stored chunk
 * indexes). `expires_at` is refreshed on every chunk PUT — expiry is 24h
 * *idle*, not 24h absolute. Mutable: sessions are coordination state, not
 * content — only document_versions is immutable (007-D06).
 *
 * @property int $size
 * @property int $chunk_size
 * @property list<int> $received_chunks
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $completed_at
 * @property string $status
 */
class UploadSession extends Model
{
    use HasUuids;

    /**
     * Mirrors the upload_sessions_status_check constraint.
     *
     * @var list<string>
     */
    public const STATUSES = ['active', 'completed', 'cancelled', 'expired'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'created_by',
        'filename',
        'size',
        'expected_sha256',
        'mime_hint',
        'chunk_size',
        'received_chunks',
        'status',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'chunk_size' => 'integer',
            'received_chunks' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalChunks(): int
    {
        return (int) ceil($this->size / max(1, $this->chunk_size));
    }

    public function isExpired(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
