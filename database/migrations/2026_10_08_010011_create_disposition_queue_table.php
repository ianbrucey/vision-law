<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disposition queue (007, DOC-29). destroy/extend/archive decisions;
     * destroy requires dual control — two distinct approvers recorded in
     * the approvals jsonb array (each {approver_id, decided_at}).
     */
    public function up(): void
    {
        Schema::create('disposition_queue', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('document_id')->constrained('documents');
            $table->text('action');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->jsonb('approvals')->default(DB::raw("'[]'::jsonb"));
            $table->text('status')->default('pending');
            $table->timestampTz('new_retention_date')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE disposition_queue ADD CONSTRAINT disposition_queue_action_check
            CHECK (action IN ('destroy', 'extend', 'archive'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE disposition_queue ADD CONSTRAINT disposition_queue_status_check
            CHECK (status IN ('pending', 'approved', 'rejected', 'executed'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disposition_queue');
    }
};
