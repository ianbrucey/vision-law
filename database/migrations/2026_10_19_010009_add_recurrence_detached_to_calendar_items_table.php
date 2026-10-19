<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_items', function (Blueprint $table) {
            // T-05 detachment convention: editing a single occurrence
            // with scope=this keeps series_id for provenance and flags
            // the row recurrence_detached=true; following/series edits
            // skip detached rows (except the row an edit starts from).
            $table->boolean('recurrence_detached')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_items', function (Blueprint $table) {
            $table->dropColumn('recurrence_detached');
        });
    }
};
