<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('predecessor_item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->foreignUuid('successor_item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['predecessor_item_id', 'successor_item_id']);
            $table->index('successor_item_id');
            $table->index('predecessor_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
