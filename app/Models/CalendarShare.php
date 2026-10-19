<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outside-counsel guest share of a single calendar item (009 T-14,
 * 009-D22). `fields` is the allowlist of item fields the guest may
 * see; only the sha256 hash of the opaque token is stored.
 *
 * @property string $id
 * @property string $item_id
 * @property string $guest_email
 * @property list<string> $fields
 * @property string $token_hash
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CalendarShare extends Model
{
    use HasUuids;

    /** @var list<string> */
    public const ALLOWED_FIELDS = ['title', 'date', 'time', 'location', 'description'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'item_id',
        'guest_email',
        'fields',
        'token_hash',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CalendarItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
