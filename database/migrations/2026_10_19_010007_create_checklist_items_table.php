<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->string('label', 500);
            $table->boolean('required')->default(false);
            $table->boolean('done')->default(false);
            $table->foreignUuid('done_by')->nullable()->constrained('users');
            $table->timestampTz('done_at')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->index(['item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
