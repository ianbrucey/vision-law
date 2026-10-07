<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Well-known UUID of the system organization (001-D13): the tenant that
     * owns audit events no real org can be attributed to (e.g. login failures
     * for unknown emails). Never listed, never assignable to users.
     */
    public const SYSTEM_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * The system organization, created on first use.
     */
    public static function system(): self
    {
        // NOTE: 'id' is intentionally NOT in $fillable, so firstOrCreate()
        // would silently drop the explicit UUID and HasUuids would generate a
        // v7 instead. Direct assignment bypasses mass-assignment guards here.
        $org = static::query()->where('id', static::SYSTEM_ID)->first();
        if ($org === null) {
            $org = new self(['name' => 'System', 'slug' => 'system']);
            $org->id = static::SYSTEM_ID;
            $org->save();
        }

        return $org;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'org_id');
    }

    /**
     * @return HasMany<Matter, $this>
     */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class, 'org_id');
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'org_id');
    }
}
