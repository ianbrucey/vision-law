<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 006-D02: replace the 001 stub's `status` with the lifecycle state
     * machine. Columns are added nullable, backfilled, then tightened —
     * so the migration is safe on tables that already hold rows.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::table('matters', function (Blueprint $table) {
            $table->string('lifecycle_state', 30)->nullable();
            $table->string('matter_type', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('client_name', 255)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('template_id')->nullable();
            $table->integer('template_version')->nullable();
        });

        // Backfill: every 001-stub row was 'open' → INTAKE. Non-open legacy
        // rows (none expected) land in a valid state rather than violating
        // the CHECK below. client_name falls back to the title — the only
        // display string the stub rows carry.
        DB::table('matters')->where('status', 'open')->update(['lifecycle_state' => 'INTAKE']);
        DB::table('matters')->whereNull('lifecycle_state')->update(['lifecycle_state' => 'INTAKE']);
        DB::table('matters')->whereNull('matter_type')->update(['matter_type' => 'other']);
        DB::table('matters')->whereNull('client_name')->update(['client_name' => DB::raw('title')]);

        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        DB::statement('ALTER TABLE matters ALTER COLUMN lifecycle_state SET NOT NULL');
        DB::statement('ALTER TABLE matters ALTER COLUMN matter_type SET NOT NULL');
        DB::statement('ALTER TABLE matters ALTER COLUMN client_name SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE matters ADD CONSTRAINT matters_lifecycle_state_check
            CHECK (lifecycle_state IN (
                'INTAKE', 'ACTIVE', 'DISCOVERY', 'PRE_TRIAL',
                'TRIAL_SETTLEMENT', 'CLOSED', 'RETENTION_HOLD', 'DISPOSITION'
            ))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE matters ADD CONSTRAINT matters_matter_type_check
            CHECK (matter_type IN (
                'litigation', 'transactional', 'regulatory', 'employment',
                'real_estate', 'estate_planning', 'other'
            ))
            SQL);

        DB::statement('CREATE INDEX matters_org_state ON matters (org_id, lifecycle_state) WHERE deleted_at IS NULL');
        DB::statement(<<<'SQL'
            CREATE INDEX matters_search_trgm ON matters
            USING gin (title gin_trgm_ops, client_name gin_trgm_ops, matter_number gin_trgm_ops)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS matters_search_trgm');
        DB::statement('DROP INDEX IF EXISTS matters_org_state');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_lifecycle_state_check');
        DB::statement('ALTER TABLE matters DROP CONSTRAINT IF EXISTS matters_matter_type_check');

        Schema::table('matters', function (Blueprint $table) {
            $table->string('status', 30)->default('open');
        });

        // The 001 stub only knew 'open' — the reverse mapping is total.
        DB::table('matters')->update(['status' => 'open']);

        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_state',
                'matter_type',
                'description',
                'client_name',
                'deleted_at',
                'closed_at',
                'template_id',
                'template_version',
            ]);
        });
    }
};
