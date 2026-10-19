<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $org_id
 * @property string $matter_id
 * @property string $item_id
 * @property string|null $policy_id
 * @property string $kind
 * @property string $recipient_user_id
 * @property string|null $recipient_email
 * @property string $channel
 * @property string $idempotency_key
 * @property CarbonInterface $scheduled_for
 * @property CarbonInterface|null $next_attempt_at
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $provider_ref
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property CarbonInterface|null $snoozed_until
 * @property array<string, mixed> $payload
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ReminderDelivery extends Model
{
    use HasUuids;

    /** @var list<string> */
    public const STATUSES = [
        'pending', 'claimed', 'sent', 'failed', 'dead', 'superseded',
        'suppressed_no_access', 'failed_unconfigured', 'acknowledged', 'snoozed',
    ];

    /** @var list<string> */
    public const CHANNELS = ['in_app', 'email'];

    /** @var list<string> */
    public const KINDS = ['reminder', 'notice', 'digest', 'escalation'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'item_id',
        'policy_id',
        'kind',
        'recipient_user_id',
        'recipient_email',
        'channel',
        'idempotency_key',
        'scheduled_for',
        'next_attempt_at',
        'status',
        'attempts',
        'last_error',
        'provider_ref',
        'sent_at',
        'acknowledged_at',
        'acknowledged_by',
        'snoozed_until',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'payload' => 'array',
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
     * @return BelongsTo<CalendarItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CalendarItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<ReminderPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ReminderPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
