<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        PostgresGrammar::macro('typeInet', fn (Fluent $column) => 'inet');

        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->string('event', 100);
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->foreignUuid('matter_id')->nullable()->constrained('matters');
            $table->addColumn('inet', 'ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'event']);
            $table->index(['matter_id', 'created_at']);
            $table->index('actor_id');
        });

        // 001-D04: append-only enforcement at the DB level.
        $appRole = (string) DB::getConfig('username');
        DB::statement('REVOKE UPDATE, DELETE ON audit_events FROM "'.$appRole.'"');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the REVOKE before dropping (dev-only path).
        $appRole = (string) DB::getConfig('username');
        DB::statement('GRANT UPDATE, DELETE ON audit_events TO "'.$appRole.'"');

        Schema::dropIfExists('audit_events');
    }
};
