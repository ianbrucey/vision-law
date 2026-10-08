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
        Schema::create('matter_parties', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->string('party_type', 30);
            $table->string('name', 255);
            $table->text('role_description')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('matter_id');
            $table->index('org_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE matter_parties ADD CONSTRAINT matter_parties_party_type_check
            CHECK (party_type IN (
                'client', 'opposing_party', 'opposing_counsel',
                'witness', 'expert', 'court', 'other'
            ))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The CHECK constraint dies with the table.
        Schema::dropIfExists('matter_parties');
    }
};
