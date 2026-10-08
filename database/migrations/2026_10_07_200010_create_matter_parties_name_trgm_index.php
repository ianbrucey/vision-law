<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * GIN trigram index on party names — searchMatters() matches party
     * names with the trigram % operator (MAT-15), so the EXISTS subquery
     * needs an index or every search seq-scans the parties table.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS matter_parties_name_trgm ON matter_parties USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS matter_parties_name_trgm');
    }
};
