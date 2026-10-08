<?php

namespace Tests\Helpers;

use App\Models\DocumentFolder;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\MatterComment;
use App\Models\MatterDocumentLog;
use App\Models\MatterGrant;
use App\Models\MatterLink;
use App\Models\MatterParty;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Support\Facades\DB;
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

    private function __construct(string $fixturePath)
    {
        $json = file_get_contents($fixturePath);
        if ($json === false) {
            throw new \RuntimeException("Fixture file not found: {$fixturePath}");
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->data = $data;
    }

    public static function load(): self
    {
        $loader = new self(base_path('specs/001-foundation-auth-rbac-audit/04-fixtures.json'));
        $loader->run();

        return $loader;
    }

    /**
     * Loads specs/006-matter-model/04-fixtures.json — the SINGLE canonical
     * fixture definition for the matter model (006 T-01). Self-contained:
     * orgs, users in every role (incl. the cross-tenant adversary, the
     * unassigned viewer, and outside counsel with a single-matter grant),
     * matters across lifecycle states, parties of all six types, a threaded
     * comment set (a 25-hour-old comment and a tombstone), document-log rows
     * in both directions, a bidirectional matter link, and grants including
     * an expired one.
     */
    public static function loadMatterFixtures(): self
    {
        $loader = new self(base_path('specs/006-matter-model/04-fixtures.json'));
        $loader->runMatterFixtures();

        return $loader;
    }

    /**
     * Links whose target matter may not be loaded yet are deferred until
     * every matter exists, then written in canonical order.
     *
     * @var list<array{org_id: string, matter_id: string, related: string, link_type: string, note: ?string}>
     */
    private array $pendingLinks = [];

    private function runMatterFixtures(): void
    {
        foreach ($this->data['fixtures'] ?? [] as $item) {
            switch ($item['type']) {
                case 'organization':
                    $this->loadMatterOrg($item);
                    break;
                case 'user':
                    $this->loadMatterUser($item);
                    break;
                case 'matter':
                    $this->loadMatterMatter($item);
                    break;
                case 'grant':
                    $this->loadMatterGrant(
                        $this->ids[$item['org']],
                        $this->ids[$item['matter']],
                        $item
                    );
                    break;
                default:
                    throw new \RuntimeException("Unknown 006 fixture type: {$item['type']}");
            }
        }

        $this->flushPendingLinks();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function loadMatterOrg(array $org): void
    {
        $model = Organization::firstOrCreate(
            ['slug' => $org['slug']],
            ['name' => $org['name']]
        );

        PermissionMatrixSeeder::seedFor($model);

        $this->ids[$org['id']] = (string) $model->getKey();
    }

    private function loadMatterUser(array $user): void
    {
        $orgId = $this->ids[$user['org']];

        $model = User::firstOrCreate(
            ['org_id' => $orgId, 'email' => $user['email']],
            [
                'name' => $user['name'],
                // The 'hashed' cast hashes this on set.
                'password' => self::DEFAULT_PASSWORD,
            ]
        );

        if ($model->email_verified_at === null) {
            $model->forceFill(['email_verified_at' => now()])->save();
        }

        $guard = (string) config('auth.defaults.guard', 'web');

        $role = Role::where('org_id', $orgId)
            ->where('name', $user['role'])
            ->where('guard_name', $guard)
            ->firstOrFail();

        $model->assignRole($role);

        $this->ids[$user['id']] = (string) $model->getKey();
    }

    /**
     * @param  array<string, mixed>  $matter
     */
    private function loadMatterMatter(array $matter): void
    {
        $orgId = $this->ids[$matter['org']];

        $model = Matter::firstOrCreate(
            [
                'org_id' => $orgId,
                'matter_number' => $matter['matter_number'],
            ],
            [
                'title' => $matter['title'],
                'lifecycle_state' => $matter['lifecycle_state'],
                'matter_type' => $matter['matter_type'] ?? 'other',
                'description' => $matter['description'] ?? null,
                'client_name' => $matter['client_name'] ?? $matter['title'],
            ]
        );

        $matterId = (string) $model->getKey();
        $this->ids[$matter['id']] = $matterId;

        foreach ($matter['parties'] ?? [] as $party) {
            MatterParty::firstOrCreate(
                [
                    'matter_id' => $matterId,
                    'party_type' => $party['party_type'],
                    'name' => $party['name'],
                ],
                [
                    'org_id' => $orgId,
                    'role_description' => $party['role_description'] ?? null,
                    'email' => $party['email'] ?? null,
                    'phone' => $party['phone'] ?? null,
                    'address' => $party['address'] ?? null,
                ]
            );
        }

        foreach ($matter['comments'] ?? [] as $comment) {
            $this->loadMatterComment($comment, $orgId, $matterId, null);
        }

        foreach ($matter['document_log'] ?? [] as $entry) {
            MatterDocumentLog::firstOrCreate(
                [
                    'matter_id' => $matterId,
                    'direction' => $entry['direction'],
                    'counterparty' => $entry['counterparty'],
                    'method' => $entry['method'],
                ],
                [
                    'org_id' => $orgId,
                    'logged_at' => now(),
                    'notes' => $entry['notes'] ?? null,
                    'annotations' => [],
                ]
            );
        }

        foreach ($matter['grants'] ?? [] as $grant) {
            $this->loadMatterGrant($orgId, $matterId, $grant);
        }

        foreach ($matter['links'] ?? [] as $link) {
            $this->pendingLinks[] = [
                'org_id' => $orgId,
                'matter_id' => $matterId,
                'related' => $link['related'],
                'link_type' => $link['link_type'],
                'note' => $link['note'] ?? null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function loadMatterComment(array $comment, string $orgId, string $matterId, ?string $parentId): void
    {
        $model = MatterComment::firstOrCreate(
            [
                'matter_id' => $matterId,
                'author_id' => $this->ids[$comment['author']],
                'body' => $comment['body'],
            ],
            [
                'org_id' => $orgId,
                'parent_id' => $parentId,
            ]
        );

        if (isset($comment['created_at']) && $model->wasRecentlyCreated) {
            // created_at is deliberately not fillable — set it directly so
            // the 25-hour-old fixture comment really is 25 hours old.
            $model->forceFill(['created_at' => $this->parseRelativeTime($comment['created_at'])])->save();
        }

        if (! empty($comment['deleted'])) {
            // Tombstone — the row and its author are retained.
            $model->delete();
        }

        $commentId = (string) $model->getKey();
        if (isset($comment['id'])) {
            $this->ids[$comment['id']] = $commentId;
        }

        foreach ($comment['replies'] ?? [] as $reply) {
            $this->loadMatterComment($reply, $orgId, $matterId, $commentId);
        }
    }

    /**
     * @param  array<string, mixed>  $grant
     */
    private function loadMatterGrant(string $orgId, string $matterId, array $grant): void
    {
        MatterGrant::firstOrCreate(
            [
                'matter_id' => $matterId,
                'user_id' => $this->ids[$grant['user']],
            ],
            [
                'org_id' => $orgId,
                'role' => $grant['role'],
                'expires_at' => isset($grant['expires_at'])
                    ? $this->parseRelativeTime($grant['expires_at'])
                    : null,
                'granted_by' => $this->adminIdFor($orgId),
            ]
        );
    }

    private function flushPendingLinks(): void
    {
        foreach ($this->pendingLinks as $link) {
            $relatedId = $this->ids[$link['related']];

            // Canonical ordering: matter_id < related_matter_id.
            [$low, $high] = $link['matter_id'] < $relatedId
                ? [$link['matter_id'], $relatedId]
                : [$relatedId, $link['matter_id']];

            MatterLink::firstOrCreate(
                [
                    'org_id' => $link['org_id'],
                    'matter_id' => $low,
                    'related_matter_id' => $high,
                ],
                [
                    'link_type' => $link['link_type'],
                    'note' => $link['note'],
                ]
            );
        }

        $this->pendingLinks = [];
    }

    /**
     * Parses fixture-relative timestamps ("-25h", "+30d", "-1d").
     */
    private function parseRelativeTime(string $value): CarbonInterface
    {
        if (preg_match('/^([+-])(\d+)([hd])$/', $value, $m) !== 1) {
            throw new \RuntimeException("Unparseable relative time: {$value}");
        }

        $amount = (int) $m[2];
        $unit = $m[3] === 'h' ? 'Hours' : 'Days';

        return $m[1] === '-' ? now()->{"sub{$unit}"}($amount) : now()->{"add{$unit}"}($amount);
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
                ]
            );

            // T-04: email_verified_at is deliberately NOT fillable on the
            // User model, so firstOrCreate() would silently drop it — fixture
            // users must be verified for the login flows under test.
            if ($model->email_verified_at === null) {
                $model->forceFill(['email_verified_at' => now()])->save();
            }

            if (! empty($user['mfa'])) {
                // Fixture-only MFA enrollment. Fortify encrypts these columns
                // itself (see the note on the User model casts); the values
                // are synthetic.
                $model->forceFill([
                    'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
                    'two_factor_recovery_codes' => encrypt(json_encode(
                        array_map(fn () => RecoveryCode::generate(), range(1, 8)),
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
                    // 006-D02: the 001 stub's 'open' status backfills to INTAKE.
                    'lifecycle_state' => 'INTAKE',
                    'matter_type' => $matter['matter_type'] ?? 'other',
                    'client_name' => $matter['client_name'] ?? $matter['title'],
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

    /**
     * Loads specs/007-document-management/04-fixtures.json — the SINGLE
     * canonical fixture definition for document management (007 T-01).
     * Self-contained and idempotent: orgs/users/matters overlap with the
     * 001/006 fixtures and are found via firstOrCreate, never duplicated.
     *
     * T-01 loads orgs, users, matters, folders, retention policies, and
     * templates. Document rows are deferred to T-02 (they need the upload
     * pipeline: bytes → blob → version), shares to T-08, and matter grants
     * are left to the 006 loader's domain.
     */
    public static function loadDocumentFixtures(): self
    {
        $loader = new self(base_path('specs/007-document-management/04-fixtures.json'));
        $loader->runDocumentFixtures();

        return $loader;
    }

    private function runDocumentFixtures(): void
    {
        foreach ($this->list('organizations') as $org) {
            $model = Organization::firstOrCreate(
                ['slug' => $org['slug']],
                ['name' => $org['name']]
            );

            PermissionMatrixSeeder::seedFor($model);

            $this->ids['doc-org:'.$org['slug']] = (string) $model->getKey();
        }

        $guard = (string) config('auth.defaults.guard', 'web');

        foreach ($this->list('users') as $user) {
            $orgId = $this->ids['doc-org:'.$user['org']];

            $model = User::firstOrCreate(
                ['org_id' => $orgId, 'email' => $user['email']],
                [
                    'name' => $user['name'],
                    // The 'hashed' cast hashes this on set.
                    'password' => self::DEFAULT_PASSWORD,
                ]
            );

            if ($model->email_verified_at === null) {
                $model->forceFill(['email_verified_at' => now()])->save();
            }

            $role = Role::where('org_id', $orgId)
                ->where('name', $user['roles'][0])
                ->where('guard_name', $guard)
                ->firstOrFail();

            $model->assignRole($role);

            $this->ids['doc-user:'.$user['email']] = (string) $model->getKey();
        }

        foreach ($this->list('matters') as $matter) {
            $orgId = $this->ids['doc-org:'.$matter['org']];

            $model = Matter::firstOrCreate(
                [
                    'org_id' => $orgId,
                    'matter_number' => $matter['number'],
                ],
                [
                    'title' => $matter['title'],
                    'lifecycle_state' => 'INTAKE',
                    'matter_type' => 'other',
                    'client_name' => $matter['title'],
                ]
            );

            $this->ids['doc-matter:'.$matter['number']] = (string) $model->getKey();
        }

        foreach ($this->list('folders') as $folder) {
            $matter = Matter::findOrFail($this->ids['doc-matter:'.$folder['matter']]);

            DocumentFolder::firstOrCreate(
                [
                    'org_id' => $matter->org_id,
                    'matter_id' => $matter->getKey(),
                    'name' => $folder['name'],
                ]
            );
        }

        foreach ($this->list('retention_policies') as $policy) {
            $orgId = $this->ids['doc-org:'.$policy['org']];

            $exists = DB::table('retention_policies')
                ->where('org_id', $orgId)
                ->where('name', $policy['name'])
                ->exists();

            if (! $exists) {
                DB::table('retention_policies')->insert([
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'name' => $policy['name'],
                    'category' => $policy['category'],
                    // Value comes from our own canonical fixture file.
                    'retention_period' => DB::raw("INTERVAL '".$policy['retention_period']."'"),
                    'trigger' => $policy['trigger'],
                    'disposition' => $policy['disposition'],
                    'legal_basis' => 'Fixture policy — synthetic data.',
                    'version' => 1,
                    'status' => $policy['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ($this->list('templates') as $template) {
            $orgId = $this->ids['doc-org:'.$template['org']];

            $exists = DB::table('document_templates')
                ->where('org_id', $orgId)
                ->where('name', $template['name'])
                ->exists();

            if (! $exists) {
                DB::table('document_templates')->insert([
                    'id' => (string) Str::uuid(),
                    'org_id' => $orgId,
                    'name' => $template['name'],
                    'body_html' => '<p>Fixture template — synthetic.</p>',
                    'field_definitions' => json_encode($template['fields'] ?? [], JSON_THROW_ON_ERROR),
                    'version' => 1,
                    'status' => $template['status'],
                    'created_by' => $this->ids['doc-user:g.grant@sterling.example'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function docOrg(string $slug): Organization
    {
        return Organization::findOrFail($this->id('doc-org:'.$slug));
    }

    public function docUser(string $email): User
    {
        return User::findOrFail($this->id('doc-user:'.$email));
    }

    public function docMatter(string $number): Matter
    {
        return Matter::findOrFail($this->id('doc-matter:'.$number));
    }
}
