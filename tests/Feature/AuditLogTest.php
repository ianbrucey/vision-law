<?php

namespace Tests\Feature;

use App\Exceptions\InvalidPayloadException;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Role;
use App\Services\AuditLogger;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * C-12 core: audit rows are append-only at BOTH levels — the DB trigger
     * raises on UPDATE/DELETE and the model events throw.
     */
    public function test_audit_log_rejects_update_and_delete(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        $event = AuditLogger::log('test.append_only', $admin, ['probe' => true]);
        $id = $event->getKey();

        // Model level: updating throws.
        try {
            $event->update(['event' => 'test.mutated']);
            $this->fail('Model update on an audit row must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Model level: deleting throws.
        try {
            $event->delete();
            $this->fail('Model delete on an audit row must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // DB level: the trigger raises on UPDATE ...
        // (each attempt runs in a savepoint so the trigger's exception does
        // not poison the test's own transaction).
        try {
            DB::transaction(fn () => DB::table('audit_events')->where('id', $id)->update(['event' => 'test.mutated']));
            $this->fail('DB update on an audit row must raise.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // ... and on DELETE.
        try {
            DB::transaction(fn () => DB::table('audit_events')->where('id', $id)->delete());
            $this->fail('DB delete on an audit row must raise.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // The row is untouched.
        $this->assertSame(1, AuditEvent::where('id', $id)->count());
        $this->assertSame('test.append_only', AuditEvent::findOrFail($id)->event);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function privilegedPayloads(): array
    {
        return [
            'password key' => [['password' => 'hunter2-hunter2']],
            'totp secret key' => [['totp_secret' => 'JBSWY3DPEHPK3PXP']],
            'recovery codes key' => [['two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd']]],
            'token key' => [['token' => 'abc123']],
            'remember token key' => [['remember_token' => 'abc123']],
            'password hash key' => [['password_hash' => '$argon2id$v=19$m=65536']],
            'recovery key' => [['recovery_codes' => ['x']]],
            'nested secret key' => [['auth' => ['secret' => 'x']]],
            'case-insensitive match' => [['TwoFactor_Secret' => 'x']],
        ];
    }

    /**
     * The privileged-key blocklist (03-contract.md): AuditLogger::log throws
     * when the payload contains password/secret/token/hash/recovery keys, and
     * writes nothing.
     */
    #[DataProvider('privilegedPayloads')]
    public function test_audit_payload_rejects_privileged_keys(array $payload): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        $before = AuditEvent::count();

        try {
            AuditLogger::log('test.privileged', $admin, $payload);
            $this->fail('AuditLogger::log must throw on privileged payload keys.');
        } catch (InvalidPayloadException) {
            // Expected: privileged data never reaches the audit table.
        }

        $this->assertSame($before, AuditEvent::count(), 'Rejected payloads must not write audit rows.');
    }

    /**
     * Clean payloads are accepted and hash-chained: genesis prev_hash is 64
     * zeros, each row links to the previous row_hash of its org.
     */
    public function test_audit_logger_writes_hash_chained_rows(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        $first = AuditLogger::log('test.first', $admin, ['n' => 1]);
        $second = AuditLogger::log('test.second', $admin, ['n' => 2]);

        $this->assertSame(str_repeat('0', 64), $first->prev_hash);
        $this->assertSame($first->row_hash, $second->prev_hash);
        $this->assertNotSame($first->row_hash, $second->row_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->row_hash);
        $this->assertSame('test.first', $first->event);
        $this->assertSame($admin->getKey(), (string) $first->actor_id);
        $this->assertSame(['n' => 1], $first->payload);
    }

    /**
     * Hash chains are per-org: each org's genesis row starts at 64 zeros.
     */
    public function test_audit_hash_chain_is_per_org(): void
    {
        $fixtures = FixtureLoader::load();

        $sterling = AuditLogger::log('test.org', $fixtures->user('user_admin'), []);
        $rival = AuditLogger::log('test.org', $fixtures->user('user_rival'), []);

        $this->assertSame(str_repeat('0', 64), $sterling->prev_hash);
        $this->assertSame(str_repeat('0', 64), $rival->prev_hash);
        $this->assertNotSame($sterling->row_hash, $rival->row_hash);
    }

    /**
     * The five-role permission matrix from 03-contract.md is seeded per org.
     */
    public function test_permission_matrix_seeded_from_contract(): void
    {
        $fixtures = FixtureLoader::load();
        $orgId = $fixtures->org('org_sterling')->getKey();
        $guard = (string) config('auth.defaults.guard', 'web');

        foreach (PermissionMatrixSeeder::MATRIX as $roleName => $expectedPermissions) {
            $role = Role::where('org_id', $orgId)
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->firstOrFail();

            $actual = $role->permissions()->pluck('name')->sort()->values()->all();
            sort($expectedPermissions);

            $this->assertSame($expectedPermissions, $actual, "Permission matrix mismatch for role {$roleName}");
        }

        // Spot-checks against the contract matrix.
        $this->assertTrue($fixtures->user('user_admin')->can('org.audit.view'));
        $this->assertFalse($fixtures->user('user_attorney_granted')->can('org.audit.view'));
        $this->assertFalse($fixtures->user('user_outside')->can('org.directory.view'));
        $this->assertTrue($fixtures->user('user_viewer')->can('org.directory.view'));
    }

    /**
     * Every adv_* case in 04-fixtures.json materializes in the DB.
     */
    public function test_fixture_loader_loads_all_adversarial_cases(): void
    {
        $fixtures = FixtureLoader::load();

        $json = file_get_contents(base_path('specs/001-foundation-auth-rbac-audit/04-fixtures.json'));
        $this->assertIsString($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<int, array<string, string>> $cases */
        $cases = $data['adversarial_cases'];
        $this->assertNotEmpty($cases, '04-fixtures.json must define adversarial cases');

        $seen = [];
        foreach ($cases as $case) {
            $this->assertAdversarialCaseMaterialized($fixtures, $case['id'], $case['description'] ?? '');
            $seen[] = $case['id'];
        }

        $this->assertSame($fixtures->adversarialCaseIds(), $seen);
    }

    private function assertAdversarialCaseMaterialized(FixtureLoader $fixtures, string $caseId, string $description): void
    {
        $sterlingId = $fixtures->org('org_sterling')->getKey();
        $matter001Id = $fixtures->matter('matter_001')->getKey();
        $matter002Id = $fixtures->matter('matter_002')->getKey();

        match ($caseId) {
            // user_attorney_nogrant requests matter_001: the actor and the
            // matter exist, but no grant connects them.
            'adv_no_grant' => $this->assertFalse(
                $fixtures->user('user_attorney_nogrant')->matterGrants()
                    ->where('matter_id', $matter001Id)->exists(),
                $description
            ),
            // user_attorney_granted requests matter_002: an EXPIRED grant exists.
            'adv_expired_grant' => $this->assertTrue(
                $fixtures->user('user_attorney_granted')->matterGrants()
                    ->where('matter_id', $matter002Id)
                    ->where('expires_at', '<', now())->exists(),
                $description
            ),
            // user_rival requests matter_001: cross-org actor, no existence leak.
            'adv_cross_org' => (function () use ($fixtures, $sterlingId, $description): void {
                $rival = $fixtures->user('user_rival');
                $this->assertNotSame((string) $sterlingId, (string) $rival->org_id, $description);
                $this->assertFalse(
                    $rival->matterGrants()->where('matter_id', $fixtures->matter('matter_001')->getKey())->exists(),
                    $description
                );
            })(),
            // user_outside (viewer) POSTs a grant on matter_001: only a viewer
            // grant exists — no grant-level power.
            'adv_viewer_write' => $this->assertSame(
                'viewer',
                (string) $fixtures->user('user_outside')->matterGrants()
                    ->where('matter_id', $matter001Id)->value('role'),
                $description
            ),
            // Invitation for new@sterling.test exists (unexpired, unaccepted)
            // so Ticket 5 can attempt acceptance as the wrong signed-in user.
            'adv_wrong_user_invite' => $this->assertSame(
                1,
                Invitation::where('org_id', $sterlingId)
                    ->where('email', 'new@sterling.test')
                    ->where('expires_at', '>', now())
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->count(),
                $description
            ),
            // user_outside cannot enumerate the org directory.
            'adv_outside_enumeration' => (function () use ($fixtures, $description): void {
                $outside = $fixtures->user('user_outside');
                $this->assertTrue($outside->hasRole('outside_counsel'), $description);
                $this->assertFalse($outside->can('org.directory.view'), $description);
            })(),
            // Auth-flow cases (Ticket 4 behavior): the actors materialize here.
            'adv_weak_password', 'adv_brute_force' => $this->assertNotNull(
                $fixtures->user('user_viewer')->getKey(),
                $description
            ),
            default => $this->fail("Unknown adversarial case in 04-fixtures.json: {$caseId}"),
        };
    }
}
