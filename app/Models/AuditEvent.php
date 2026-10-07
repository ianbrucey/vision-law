<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasUuids;

    /**
     * Append-only: no updated_at column exists (001-D04). Only created_at is
     * managed by Eloquent.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'actor_id',
        'event',
        'auditable_type',
        'auditable_id',
        'matter_id',
        'ip',
        'user_agent',
        'payload',
        'prev_hash',
        'row_hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Defense-in-depth behind the DB trigger (001-D11): model-level
        // mutation attempts throw before ever reaching the database.
        static::updating(fn () => throw new \RuntimeException(
            'audit_events is append-only: updates are forbidden'
        ));
        static::deleting(fn () => throw new \RuntimeException(
            'audit_events is append-only: deletes are forbidden'
        ));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'matter_id');
    }
}
