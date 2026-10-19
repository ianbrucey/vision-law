<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_policies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->integer('version');
            $table->boolean('active')->default(true);
            $table->jsonb('rules')->default(DB::raw("'[]'::jsonb"));
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['item_id', 'version']);
            $table->index(['item_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_policies');
    }
};
