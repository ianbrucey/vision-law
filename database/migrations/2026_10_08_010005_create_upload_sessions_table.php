<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chunked/resumable upload sessions (007, 007-D05): 8 MiB chunks,
     * idempotent per-chunk PUT, resume via the received_chunks bitmap, total
     * SHA-256 verified on complete. Sessions expire after 24h idle; a
     * sweeper (T-02) garbage-collects expired sessions and their chunks.
     *
     * T-02 owns the controller/service surface; T-01 lays the schema so the
     * storage foundation is complete.
     */
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('created_by')->constrained('users');
            $table->text('filename');
            $table->bigInteger('size');
            $table->char('expected_sha256', 64);
            $table->text('mime_hint')->nullable();
            $table->integer('chunk_size')->default(8388608);
            $table->jsonb('received_chunks')->default(DB::raw("'[]'::jsonb"));
            $table->text('status')->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['matter_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_status_check
            CHECK (status IN ('active', 'completed', 'cancelled', 'expired'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
