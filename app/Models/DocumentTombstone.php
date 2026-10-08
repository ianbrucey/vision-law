<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permanent tombstone (007, DOC-29). After dual-control destruction the
 * document row is gone but this record survives: org, matter, title,
 * content hash, when, by whom, and the approvals. Write-once by
 * convention — destruction flows never update a tombstone.
 *
 * @property CarbonInterface $destroyed_at
 * @property list<array{approver_id: string, decided_at: string}> $approvals
 */
class DocumentTombstone extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'title',
        'sha256',
        'destroyed_at',
        'destroyed_by',
        'approvals',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destroyed_at' => 'datetime',
            'approvals' => 'array',
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
    public function destroyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destroyed_by');
    }
}
