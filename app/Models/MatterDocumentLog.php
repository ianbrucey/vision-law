<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Received/sent document register (MAT-04). Append-only by design: no
 * updated_at column, core fields immutable after insert — corrections are
 * new rows. Only `annotations` is mutable.
 *
 * @property CarbonInterface $logged_at
 * @property array<string, mixed> $annotations
 */
class MatterDocumentLog extends Model
{
    use HasUuids;

    /**
     * Mirrors the matter_document_log_direction_check constraint.
     *
     * @var list<string>
     */
    public const DIRECTIONS = ['received', 'sent'];

    /**
     * Mirrors the matter_document_log_method_check constraint.
     *
     * @var list<string>
     */
    public const METHODS = ['upload', 'email', 'share_link', 'integration'];

    /**
     * No updated_at — the log is append-only.
     */
    public const UPDATED_AT = null;

    /**
     * The schema delta names this table in the singular.
     *
     * @var string
     */
    protected $table = 'matter_document_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'matter_id',
        'direction',
        'counterparty',
        'logged_at',
        'method',
        'notes',
        'annotations',
    ];

    /**
     * @var list<string>
     */
    protected $touches = ['matter'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'annotations' => 'array',
            'created_at' => 'datetime',
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
}
