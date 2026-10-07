<?php

namespace Tests\Helpers;

use App\Models\Invitation;
use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Support\Str;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

/**
 * Loads specs/001-foundation-auth-rbac-audit/04-fixtures.json — the SINGLE
 * canonical fixture definition (no hand-written duplicate). Shared by feature
 * tests and local-dev seeders (via DevFixtureSeeder).
 *
 * Fixture IDs in the JSON are symbolic ("matter_001"); the loader maps them to
 * the real UUIDs it creates — use id()/user()/matter()/team()/org() to
 * resolve them.
 */
class FixtureLoader
{
    /**
     * Default password for every fixture user. Satisfies the contract's
     * password policy (min 12 chars); Ticket 4's login tests use this constant.
     */
    public const DEFAULT_PASSWORD = 'Sterling-Fixtures-2026!';

    /**
     * Plaintext invitation token for the adv_wrong_user_invite case
     * (new@sterling.test). Only the SHA-256 hash is stored in the DB. Null on
     * re-load when the invitation already existed.
     */
    private ?string $invitationToken = null;

    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string> symbolic id => real UUID */
    private array $ids = [];

    private function __construct()
    {
        $path = base_path('specs/001-foundation-auth-rbac-audit/04-fixtures.json');
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Fixture file not found: {$path}");
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->data = $data;
    }

    public static function load(): self
    {
        $loader = new self;
        $loader->run();

        return $loader;
    }

    private function run(): void
    {
        $this->loadOrganizations();
        $this->loadUsers();
        $this->loadMatters();
        $this->loadTeams();
        $this->loadMatterGrants();
        $this->loadAdversarialInvitation();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(string $key): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $this->data[$key] ?? [];

        return $items;
    }

    private function loadOrganizations(): void
    {
        foreach ($this->list('organizations') as $org) {
            $model = Organization::firstOrCreate(
                ['slug' => $org['slug']],
                [
                    'name' => $org['name'],
                    'settings' => $org['settings'] ?? [],
                ]
            );

            PermissionMatrixSeeder::seedFor($model);

            $this->ids[$org['id']] = (string) $model->getKey();
        }
    }

    private function loadUsers(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        foreach ($this->list('users') as $user) {
            $orgId = $this->ids[$user['org']];

            $model = User::firstOrCreate(
                ['org_id' => $orgId, 'email' => $user['email']],
                [
                    'name' => $user['name'],
                    // The 'hashed' cast hashes this on set.
                    'password' => self::DEFAULT_PASSWORD,
                    'email_verified_at' => now(),
                ]
            );

            if (! empty($user['mfa'])) {
                // Fixture-only MFA enrollment. Fortify encrypts these columns
                // itself (see the note on the User model casts); the values
                // are synthetic.
                $model->forceFill([
                    'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
                    'two_factor_recovery_codes' => encrypt(json_encode(
                        array_map(fn () => RecoveryCode::generate(), range(1, 10)),
                        JSON_THROW_ON_ERROR
                    )),
                    'two_factor_confirmed_at' => now(),
                ])->save();
            }

            $role = Role::where('org_id', $orgId)
                ->where('name', $user['role'])
                ->where('guard_name', $guard)
                ->firstOrFail();

            $model->assignRole($role);

            $this->ids[$user['id']] = (string) $model->getKey();
        }
    }

    private function loadMatters(): void
    {
        foreach ($this->list('matters') as $matter) {
            $model = Matter::firstOrCreate(
                [
                    'org_id' => $this->ids[$matter['org']],
                    'matter_number' => $matter['matter_number'],
                ],
                [
                    'title' => $matter['title'],
                    'status' => $matter['status'],
                ]
            );

            $this->ids[$matter['id']] = (string) $model->getKey();
        }
    }

    private function loadTeams(): void
    {
        foreach ($this->list('teams') as $team) {
            $model = Team::firstOrCreate([
                'org_id' => $this->ids[$team['org']],
                'name' => $team['name'],
            ]);

            $this->ids[$team['id']] = (string) $model->getKey();

            foreach ($team['members'] as $memberKey) {
                $model->users()->syncWithoutDetaching([$this->ids[$memberKey]]);
            }
        }
    }

    private function loadMatterGrants(): void
    {
        foreach ($this->list('matter_grants') as $grant) {
            $matter = Matter::findOrFail($this->ids[$grant['matter']]);
            $orgId = (string) $matter->org_id;

            $attributes = ['matter_id' => $matter->getKey()];
            if ($grant['subject_type'] === 'team') {
                $attributes['team_id'] = $this->ids[$grant['subject']];
            } else {
                $attributes['user_id'] = $this->ids[$grant['subject']];
            }

            MatterGrant::firstOrCreate($attributes, [
                'org_id' => $orgId,
                'role' => $grant['role'],
                'expires_at' => ! empty($grant['expired']) ? now()->subDay() : null,
                'granted_by' => $this->adminIdFor($orgId),
            ]);
        }
    }

    /**
     * adv_wrong_user_invite: an invitation for new@sterling.test exists so the
     * Ticket 5 acceptance test can attempt to accept it as the wrong user.
     */
    private function loadAdversarialInvitation(): void
    {
        $orgId = $this->ids['org_sterling'];
        $token = Str::random(64);

        $invitation = Invitation::firstOrCreate(
            ['org_id' => $orgId, 'email' => 'new@sterling.test'],
            [
                'token_hash' => hash('sha256', $token),
                'role' => 'viewer',
                'invited_by' => $this->adminIdFor($orgId),
                'expires_at' => now()->addDays(7),
            ]
        );

        if ($invitation->wasRecentlyCreated) {
            $this->invitationToken = $token;
        }
    }

    private function adminIdFor(string $orgId): string
    {
        $admin = User::where('org_id', $orgId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'org_admin'))
            ->firstOrFail();

        return (string) $admin->getKey();
    }

    public function id(string $symbolic): string
    {
        if (! isset($this->ids[$symbolic])) {
            throw new \InvalidArgumentException("Unknown fixture id: {$symbolic}");
        }

        return $this->ids[$symbolic];
    }

    public function org(string $key): Organization
    {
        return Organization::findOrFail($this->id($key));
    }

    public function user(string $key): User
    {
        return User::findOrFail($this->id($key));
    }

    public function matter(string $key): Matter
    {
        return Matter::findOrFail($this->id($key));
    }

    public function team(string $key): Team
    {
        return Team::findOrFail($this->id($key));
    }

    public function invitationToken(): ?string
    {
        return $this->invitationToken;
    }

    /**
     * @return list<string>
     */
    public function adversarialCaseIds(): array
    {
        return array_column($this->list('adversarial_cases'), 'id');
    }
}
