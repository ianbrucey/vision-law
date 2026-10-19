<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a DeadlineTemplate's rule content (009-D18, T-10).
 *
 * Lifecycle: draft → pending_review → published → retired, driven
 * only by TemplateService. Published versions are immutable: the
 * service refuses in-place edits and forks a new draft instead.
 * `rules` holds the engine ruleset verbatim — either the offset-rule
 * list or the step-rule content envelope (009-D19; DeadlineEngine
 * validates and evaluates both).
 *
 * @property string $id
 * @property string $template_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed>|list<array<string, mixed>> $rules
 * @property string|null $created_by
 * @property string|null $reviewed_by
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class DeadlineTemplateVersion extends Model
{
    use HasUuids;

    /** @var list<string> */
    public const STATUSES = ['draft', 'pending_review', 'published', 'retired'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'template_id',
        'version',
        'status',
        'rules',
        'created_by',
        'reviewed_by',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rules' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DeadlineTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DeadlineTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
