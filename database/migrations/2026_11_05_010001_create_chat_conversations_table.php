<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 012 chat slice: minimal chat persistence for the FRONT-DESK
     * chat only. One conversation per user per scope in this slice
     * (enforced in the controller via firstOrCreate); matter_id stays
     * nullable so matter-scoped conversations can land later without a
     * schema change.
     */
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->string('scope')->default('front_desk');
            $table->foreignUuid('matter_id')->nullable()->constrained('matters');
            $table->string('title')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['user_id', 'scope']);
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
