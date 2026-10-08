<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\MatterComment;
use App\Models\MatterGrant;
use App\Services\MatterService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * 006 T-01 verdicts: schema, models, matter numbering, fixtures.
 */
class MatterModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two concurrent creators → distinct sequential numbers, no collision.
     *
     * Two independent OS processes (own sockets, own DB sessions) race
     * generateNumber()+insert. pg_advisory_xact_lock serializes the two
     * critical sections, so one process must observe MAX+1=1 and the
     * other MAX+1=2.
     */
    public function test_matter_number_generation(): void
    {
        // RefreshDatabase wraps this test in a transaction that other
        // processes cannot see — the org is committed on a side connection.
        $pdo = $this->sidePdo();
        $pdo->exec("DELETE FROM matters WHERE org_id IN (SELECT id FROM organizations WHERE slug LIKE 'number-gen-%')");
        $pdo->exec("DELETE FROM organizations WHERE slug LIKE 'number-gen-%'");

        $orgId = (string) Str::uuid();
        $insert = $pdo->prepare('INSERT INTO organizations (id, name, slug, settings) VALUES (?, ?, ?, ?::jsonb)');
        $insert->execute([$orgId, 'Number Gen Org', 'number-gen-'.substr($orgId, 0, 8), '{}']);

        /** @var array{host: mixed, port: mixed, database: mixed, username: mixed, password: mixed} $cfg */
        $cfg = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $cfg['host'],
            'DB_PORT' => (string) $cfg['port'],
            'DB_DATABASE' => (string) $cfg['database'],
            'DB_USERNAME' => (string) $cfg['username'],
            'DB_PASSWORD' => (string) $cfg['password'],
        ];

        $worker = base_path('tests/Helpers/concurrent_number_worker.php');
        $files = [];
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $files[$i] = tempnam(sys_get_temp_dir(), 'matnum');
                $this->assertNotFalse($files[$i]);

                $processes[$i] = new Process(
                    [PHP_BINARY, $worker, $orgId, '2026', $files[$i]],
                    null,
                    $env,
                    null,
                    60
                );
                $processes[$i]->start();
            }

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'worker failed: '.$process->getErrorOutput());
            }

            $numbers = array_map(
                fn ($f) => trim((string) file_get_contents($f)),
                $files
            );
            sort($numbers);

            $this->assertSame(['MAT-2026-0001', 'MAT-2026-0002'], $numbers);
        } finally {
            $pdo->prepare('DELETE FROM matters WHERE org_id = ?')->execute([$orgId]);
            $pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$orgId]);
            foreach ($files as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Existing 'open' rows read as INTAKE after the 006-D02 migration.
     *
     * Exercises the real migration down()/up(): down restores the 001
     * `status` column, a legacy 'open' row is inserted, up backfills it to
     * INTAKE and drops `status`.
     */
    public function test_status_backfill(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_07_200001_alter_matters_lifecycle.php');
        $migration->down();

        $orgId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'Backfill Org',
            'slug' => 'backfill-org-'.substr($orgId, 0, 8),
        ]);
        DB::table('matters')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'matter_number' => 'MAT-2026-900',
            'title' => 'Pre-migration matter',
            'status' => 'open',
        ]);

        $migration->up();

        $matter = Matter::where('matter_number', 'MAT-2026-900')->firstOrFail();
        $this->assertSame('INTAKE', $matter->lifecycle_state);
        $this->assertFalse(Schema::hasColumn('matters', 'status'));
    }

    /**
     * Numbering is sequential per org per year; soft-deleted rows keep
     * their numbers (no reuse).
     */
    public function test_generate_number_sequences(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $orgId = $loader->id('org-sterling');

        // Fixtures already hold MAT-2026-001..003 for this org.
        $this->assertSame('MAT-2026-0004', MatterService::generateNumber($orgId, 2026));
        // A generated-but-uninserted number is not consumed.
        $this->assertSame('MAT-2026-0004', MatterService::generateNumber($orgId, 2026));

        // A new year starts its own sequence.
        $this->assertSame('MAT-2027-0001', MatterService::generateNumber($orgId, 2027));

        // Consume 0004, soft-delete it, prove the number is not reused.
        $matter = Matter::create([
            'org_id' => $orgId,
            'matter_number' => MatterService::generateNumber($orgId, 2026),
            'title' => 'Numbering probe',
            'lifecycle_state' => 'INTAKE',
            'matter_type' => 'litigation',
            'client_name' => 'Probe Client',
        ]);
        $matter->delete();

        $this->assertSame('MAT-2026-0005', MatterService::generateNumber($orgId, 2026));
    }

    /**
     * loadMatterFixtures() loads every 006 fixture case, including the
     * adversarial ones (cross-tenant rival, unassigned viewer, expired
     * grant, 25-hour-old comment, tombstone).
     */
    public function test_matter_fixtures_load(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();

        $this->assertSame('Sterling & Associates LLP', $loader->org('org-sterling')->name);
        $this->assertSame('Rival Firm PC', $loader->org('org-rival')->name);

        $this->assertTrue($loader->user('user-admin')->hasRole('org_admin'));
        $this->assertTrue($loader->user('user-oc')->hasRole('outside_counsel'));
        $rival = $loader->user('user-rival');
        $this->assertSame($loader->id('org-rival'), (string) $rival->org_id);

        $m1 = $loader->matter('matter-1');
        $this->assertSame('ACTIVE', $m1->lifecycle_state);
        $this->assertSame('MAT-2026-001', $m1->matter_number);
        $this->assertSame('litigation', $m1->matter_type);
        $this->assertSame('Sterling Manufacturing Co.', $m1->client_name);
        $this->assertSame('Breach of contract — Fulton County Superior Court.', $m1->description);
        $this->assertSame('DISCOVERY', $loader->matter('matter-2')->lifecycle_state);
        $this->assertSame('INTAKE', $loader->matter('matter-3')->lifecycle_state);

        // Parties: one of client/opposing_party/opposing_counsel.
        $this->assertSame(3, $m1->parties()->count());
        $this->assertTrue($m1->parties()->where('party_type', 'client')->exists());

        // Comments: top-level + reply + 25h-old + tombstone.
        $c1 = MatterComment::findOrFail($loader->id('c1'));
        $this->assertSame(1, $c1->replies()->count());
        $this->assertSame($loader->id('c1r1'), (string) $c1->replies()->firstOrFail()->getKey());

        $c2 = MatterComment::findOrFail($loader->id('c2'));
        $this->assertTrue($c2->created_at->lt(now()->subHours(24)), 'c2 edit window must have lapsed');

        $c3 = MatterComment::withTrashed()->findOrFail($loader->id('c3'));
        $this->assertSoftDeleted($c3);

        // Document log: received + sent rows.
        $this->assertSame(2, $m1->documentLogs()->count());
        $this->assertTrue($m1->documentLogs()->where('direction', 'received')->exists());
        $this->assertTrue($m1->documentLogs()->where('direction', 'sent')->exists());

        // Bidirectional link in canonical order.
        $link = $m1->links()->firstOrFail();
        $this->assertSame('same_client', $link->link_type);
        $this->assertSame($loader->id('matter-2'), (string) $link->related_matter_id);
        $this->assertTrue($link->matter_id < $link->related_matter_id);

        // Grants: outside counsel holds matter-1 only; the viewer grant on
        // matter-2 is expired and must read as no grant.
        $this->assertTrue(
            MatterGrant::where('matter_id', $m1->getKey())
                ->where('user_id', $loader->id('user-oc'))
                ->exists()
        );
        $this->assertFalse(
            MatterGrant::where('matter_id', $loader->id('matter-2'))
                ->where('user_id', $loader->id('user-oc'))
                ->exists()
        );
        $expired = MatterGrant::where('matter_id', $loader->id('matter-2'))
            ->where('user_id', $loader->id('user-viewer'))
            ->firstOrFail();
        $this->assertTrue($expired->expires_at->isPast());
    }

    /**
     * Child mutations (comment/party/link/log) bump the matter's
     * updated_at via $touches; read markers do not.
     */
    public function test_child_touch_bumps_matter_updated_at(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $matter = $loader->matter('matter-3');

        $before = now()->subHour();
        DB::table('matters')->where('id', $matter->getKey())->update(['updated_at' => $before]);

        $matter->comments()->create([
            'org_id' => $matter->org_id,
            'author_id' => $loader->id('user-attorney'),
            'body' => 'Touch probe comment',
        ]);

        $this->assertTrue($matter->refresh()->updated_at->gt($before));
    }

    /**
     * A committed side connection: worker processes run outside
     * RefreshDatabase's transaction, so the org they need must be
     * visible to other sessions.
     */
    private function sidePdo(): PDO
    {
        /** @var array{host: string, port: string, database: string, username: string, password: string} $cfg */
        $cfg = config('database.connections.pgsql');

        return new PDO(
            "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}",
            $cfg['username'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
