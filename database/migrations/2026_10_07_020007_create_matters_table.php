<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->string('matter_number', 50);
            $table->string('title', 500);
            $table->string('status', 30)->default('open');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['org_id', 'matter_number']);
            $table->index('org_id');
        });

        // Invitations were created before matters (spec migration order);
        // attach the matter FK now that the table exists.
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreign('matter_id')->references('id')->on('matters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['matter_id']);
        });

        Schema::dropIfExists('matters');
    }
};
