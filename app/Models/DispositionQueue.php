<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disposition queue entry (007 T-09, DOC-29; table from T-01).
 *
 * A flagged (or manually nominated) document with a proposed outcome:
 * destroy / extend / archive. requested_by is null for system-initiated
 * (nightly) rows.
 *
 * approvals is a jsonb array of {approver_id, decided_at}. Destroy
 * requires TWO DISTINCT approvers (dual control) — the same user twice
 * does not count. extend/archive execute on a single org_admin decision.
 *
 * @property list<array{approver_id: string, decided_at: string}> $approvals
 * @property CarbonInterface|null $new_retention_date
 * @property CarbonInterface|null $decided_at
 */
class DispositionQueue extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'disposition_queue';

    /**
     * @var list<string>
     */
    public const ACTIONS = ['destroy', 'extend', 'archive'];

    /**
     * @var list<string>
     */
    public const STATUSES = ['pending', 'approved', 'rejected', 'executed'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'action',
        'requested_by',
        'approvals',
        'status',
        'new_retention_date',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approvals' => 'array',
            'new_retention_date' => 'datetime',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return list<string>
     */
    public function approverIds(): array
    {
        /** @var list<array{approver_id: string, decided_at: string}> $approvals */
        $approvals = $this->approvals ?? [];

        return array_values(array_unique(array_map(
            fn (array $approval): string => (string) $approval['approver_id'],
            $approvals
        )));
    }

    public function approvalCount(): int
    {
        return count($this->approverIds());
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * @param  list<array{approver_id: string, decided_at: string}>  $approvals
     */
    public function recordApproval(User $approver, array $approvals): void
    {
        $approvals[] = [
            'approver_id' => (string) $approver->getKey(),
            'decided_at' => now()->toIso8601String(),
        ];

        $this->forceFill(['approvals' => $approvals])->save();
    }
}
