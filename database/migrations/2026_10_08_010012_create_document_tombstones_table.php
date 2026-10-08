<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent tombstones (007, DOC-29). After dual-control destruction,
     * the document row is gone but this record survives: org, matter,
     * title, content hash, when, by whom, and the two approvals. Write-once
     * by convention — destruction flows never update a tombstone.
     */
    public function up(): void
    {
        Schema::create('document_tombstones', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->text('title');
            $table->char('sha256', 64);
            $table->timestampTz('destroyed_at');
            $table->foreignUuid('destroyed_by')->constrained('users');
            $table->jsonb('approvals')->default(DB::raw("'[]'::jsonb"));
            $table->timestampsTz();

            $table->index(['org_id', 'matter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_tombstones');
    }
};
