<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Deadline template catalog entry (009-D18, T-10). Org-owned;
 * distinct from 007's DocumentTemplate in model, routes, and names.
 *
 * The "current published version" is DERIVED, never stored: the
 * published version with the highest version number (see
 * currentPublishedVersion()). Publishing never retires another
 * version automatically.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string|null $jurisdiction_label
 * @property bool $demonstration
 * @property string|null $owner_label
 * @property string|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class DeadlineTemplate extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'name',
        'jurisdiction_label',
        'demonstration',
        'owner_label',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demonstration' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<DeadlineTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DeadlineTemplateVersion::class, 'template_id')->orderBy('version');
    }

    /**
     * The current published version: the latest (highest-numbered)
     * version in published status. Older published versions keep
     * their status (publishing retires nothing automatically) but
     * are no longer current.
     *
     * @return HasOne<DeadlineTemplateVersion, $this>
     */
    public function currentPublishedVersion(): HasOne
    {
        return $this->hasOne(DeadlineTemplateVersion::class, 'template_id')
            ->where('status', 'published')
            ->orderByDesc('version');
    }

    /**
     * @return HasOne<DeadlineTemplateVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DeadlineTemplateVersion::class, 'template_id')->orderByDesc('version');
    }
}
