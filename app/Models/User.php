<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, MustVerifyEmailTrait, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'name',
        'email',
        'password',
    ];

    /**
     * Leak sentinels (00-brief.md): password hashes, TOTP secrets, and backup
     * codes are privileged — never rendered, never logged.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * NOTE: two_factor_secret / two_factor_recovery_codes intentionally have
     * NO 'encrypted' cast here. Fortify's TwoFactorAuthenticatable trait and
     * its Enable/Disable actions already encrypt these columns themselves via
     * Fortify::currentEncrypter(); adding a cast would double-encrypt and
     * break recoveryCodes(). Encryption at rest is still satisfied (APP_KEY
     * envelope), per 02-schema-delta.md.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
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
     * @return BelongsToMany<Team, $this, Pivot, 'pivot'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id')
            ->withPivot('created_at');
    }

    /**
     * @return HasMany<MatterGrant, $this>
     */
    public function matterGrants(): HasMany
    {
        return $this->hasMany(MatterGrant::class, 'user_id');
    }
}
