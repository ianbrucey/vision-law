<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-02 (concurrency half): two processes racing the same ACTIVE→DISCOVERY
 * transition serialize on SELECT … FOR UPDATE — one wins, the loser
 * re-reads the row and degrades to the 006-D06 same-state no-op. No lost
 * update, no duplicate audit row.
 *
 * Runs OUTSIDE RefreshDatabase: forked children need committed rows on
 * their own connections (a transaction-wrapped fixture set would be
 * invisible to them). setUp/tearDown migrate:fresh keeps the class
 * hermetic — PHPUnit runs suites sequentially, so no other test can
 * observe the intermediate state.
 */
class MatterTransitionConcurrencyTest extends TestCase
{
    private string $matterId;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');

        $loader = FixtureLoader::loadMatterFixtures();
        $this->matterId = (string) $loader->matter('matter-1')->getKey();
        $this->actorId = (string) $loader->user('user-admin')->getKey();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }

    public function test_concurrent_transitions_serialize(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is unavailable — the concurrency assertion cannot run.');
        }

        $pids = [];

        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                $this->runChildTransition();
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                "child {$pid} did not exit cleanly (status {$status})"
            );
        }

        $matter = Matter::findOrFail($this->matterId);
        $this->assertSame('DISCOVERY', $matter->lifecycle_state);

        // Exactly one transition happened — the loser became a no-op
        // instead of clobbering the winner's write.
        $this->assertSame(1, AuditEvent::query()
            ->where('matter_id', $this->matterId)
            ->where('event', 'matter.transition')
            ->count());
    }

    /**
     * @return never
     */
    private function runChildTransition(): void
    {
        try {
            // Drop the inherited connection: a forked child must never share
            // the parent's PDO socket.
            DB::purge();

            $matter = Matter::findOrFail($this->matterId);
            $actor = User::findOrFail($this->actorId);

            MatterService::transitionMatter($matter, 'DISCOVERY', null, $actor);
            exit(0);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'child transition failed: '.$e->getMessage()."\n");
            exit(1);
        }
    }
}
