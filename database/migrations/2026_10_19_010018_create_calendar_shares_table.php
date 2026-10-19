<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->string('guest_email');
            $table->jsonb('fields')->default(DB::raw("'[]'::jsonb"));
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_shares');
    }
};
