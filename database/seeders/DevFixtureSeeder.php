<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tests\Helpers\FixtureLoader;

/**
 * Local-dev convenience seeder: loads the canonical 001 fixtures
 * (specs/001-foundation-auth-rbac-audit/04-fixtures.json) into the dev DB.
 * This is dev convenience, NOT test data — tests load fixtures directly via
 * FixtureLoader on a fresh test database.
 */
class DevFixtureSeeder extends Seeder
{
    public function run(): void
    {
        FixtureLoader::load();
    }
}
