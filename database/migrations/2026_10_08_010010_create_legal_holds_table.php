<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal holds (007, DOC-28). A hold on a matter or document blocks hard
     * delete (423), version purge, and matter archival until released.
     * Release requires the legal-hold role and a logged reason.
     */
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->nullable()->constrained('matters');
            $table->foreignUuid('document_id')->nullable()->constrained('documents');
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestampsTz();
        });

        // Active-hold lookup: one partial index keeps the hot path cheap.
        DB::statement(<<<'SQL'
            CREATE INDEX legal_holds_active_document ON legal_holds (document_id)
            WHERE released_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX legal_holds_active_matter ON legal_holds (matter_id)
            WHERE released_at IS NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};
