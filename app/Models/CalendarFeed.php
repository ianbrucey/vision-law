<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user ICS subscription feed (009 T-14, 009-D22). Only the
 * sha256 hash of the opaque token is stored; the plain token is
 * shown once at creation and never persisted.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CalendarFeed extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token_hash',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
