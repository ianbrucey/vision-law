<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External secure links (007, DOC-25). Only the SHA-256 token_hash is
     * stored — the plaintext token appears once, at creation (contract
     * §Component props). version_id pins the shared version.
     */
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->text('password_hash')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index('document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_shares');
    }
};
