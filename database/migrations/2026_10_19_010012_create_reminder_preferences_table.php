<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reminder_preferences')) {
            return;
        }

        Schema::create('reminder_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->string('timezone')->default('UTC');
            $table->boolean('digest_enabled')->default(false);
            $table->time('digest_time')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_preferences');
    }
};
