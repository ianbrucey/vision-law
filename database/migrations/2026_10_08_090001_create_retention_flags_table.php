<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 007 T-09 (DOC-28): retention flags.
     *
     * A flag marks a document as past (or approaching) its retention
     * threshold. policy_id is null for manual flags; flagged_by records
     * the human for manual flags (null for nightly auto-flagging).
     * Rows are deleted when a disposition is executed or the document is
     * hard-deleted (see DocumentFilingService::hardDelete).
     */
    public function up(): void
    {
        Schema::create('retention_flags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('document_id')->constrained('documents');
            $table->foreignUuid('policy_id')->nullable()->constrained('retention_policies')->nullOnDelete();
            $table->text('reason');
            $table->foreignUuid('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('flagged_at')->useCurrent();
            $table->timestampsTz();

            $table->index(['document_id', 'flagged_at']);
            $table->index('policy_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retention_flags');
    }
};
