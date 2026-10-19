<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_filter_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->jsonb('filters')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_filter_preferences');
    }
};
