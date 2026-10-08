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
        Schema::create('matter_comments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['matter_id', 'created_at']);
            $table->index('org_id');
        });

        // Self-referencing FK in its own statement: within a single
        // Schema::create blueprint Laravel compiles the fluent column
        // ->primary() AFTER explicitly-added foreign commands, so the
        // self-FK's ALTER would run before the PK exists (SQLSTATE 42830).
        Schema::table('matter_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('matter_comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matter_comments');
    }
};
