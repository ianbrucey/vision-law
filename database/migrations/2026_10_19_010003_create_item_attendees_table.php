<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_attendees', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->timestampsTz();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_attendees');
    }
};
