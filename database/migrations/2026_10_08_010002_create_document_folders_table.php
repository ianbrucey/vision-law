<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-matter folder tree (007, DOC-11). Delete empty-only is enforced
     * in the service layer; soft deletes keep the tree restorable.
     */
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->uuid('parent_id')->nullable();
            $table->text('name');
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['matter_id', 'parent_id']);
        });

        // Self-referencing FK in its own statement: within a single
        // Schema::create blueprint Laravel compiles the fluent column
        // ->primary() AFTER explicitly-added foreign commands, so the
        // self-FK's ALTER would run before the PK exists (SQLSTATE 42830).
        // Same lesson as 006's matter_comments migration.
        Schema::table('document_folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('document_folders')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_folders');
    }
};
