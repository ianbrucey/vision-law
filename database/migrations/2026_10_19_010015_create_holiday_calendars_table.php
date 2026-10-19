<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned holiday calendars (009-D05, T-11): one row per
     * (key, version) pack; historical versions are retained — a new
     * pack is new rows, never an edit. Packs ship as DEMONSTRATION
     * data files under database/data/holiday-calendars/ and are loaded
     * by the calendaring:load-holiday-packs command.
     */
    public function up(): void
    {
        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key');
            $table->string('version');
            $table->string('label');
            $table->boolean('demonstration')->default(true);
            $table->string('owner_label')->nullable();
            $table->timestampsTz();

            $table->unique(['key', 'version']);
        });

        Schema::create('holiday_dates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('calendar_id')->constrained('holiday_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->timestampsTz();

            $table->unique(['calendar_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_dates');
        Schema::dropIfExists('holiday_calendars');
    }
};
