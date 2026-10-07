<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matter_grants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->foreignUuid('team_id')->nullable()->constrained('teams');
            $table->string('role', 30);
            $table->timestampTz('expires_at')->nullable();
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('matter_id');
            $table->index('user_id');
        });

        // Partial uniques: one grant per (matter, user) / (matter, team).
        DB::statement('CREATE UNIQUE INDEX matter_grants_matter_user_unique ON matter_grants (matter_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX matter_grants_matter_team_unique ON matter_grants (matter_id, team_id) WHERE team_id IS NOT NULL');

        // Exactly one of user_id / team_id must be set.
        DB::statement('ALTER TABLE matter_grants ADD CONSTRAINT matter_grants_exactly_one_subject CHECK ((user_id IS NULL) <> (team_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Indexes and the CHECK constraint die with the table.
        Schema::dropIfExists('matter_grants');
    }
};
