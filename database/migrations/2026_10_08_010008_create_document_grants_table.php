<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal document grants (007, DOC-24). Narrower than matter grants:
     * effective permission = max(matter role, grant). team_id is reserved
     * for a future teams model — unused for now (schema delta).
     */
    public function up(): void
    {
        Schema::create('document_grants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('team_id')->nullable();
            $table->text('level');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->unique(['document_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_grants ADD CONSTRAINT document_grants_level_check
            CHECK (level IN ('viewer', 'commenter', 'editor'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_grants');
    }
};
