<?php

/*
 * Test-only worker for MatterModelTest::test_matter_number_generation.
 *
 * Generates one matter number and inserts the matter row inside a single
 * transaction, proving pg_advisory_xact_lock serializes concurrent
 * creators. Runs as its own OS process (no shared sockets, no forked
 * destructors) — invoked as:
 *
 *   php concurrent_number_worker.php <orgId> <year> <outFile>
 *
 * The parent test passes the testing DB credentials via the environment.
 */

use App\Services\MatterService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

[$script, $orgId, $year, $outFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $number = DB::transaction(function () use ($orgId, $year): string {
        $n = MatterService::generateNumber($orgId, (int) $year);
        DB::table('matters')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'matter_number' => $n,
            'title' => 'Race matter '.$n,
            'lifecycle_state' => 'INTAKE',
            'matter_type' => 'litigation',
            'client_name' => 'Race Client',
        ]);

        return $n;
    });
    file_put_contents($outFile, $number);
} catch (Throwable $e) {
    file_put_contents($outFile, 'ERROR '.get_class($e).': '.$e->getMessage());
    exit(1);
}
