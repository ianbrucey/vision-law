<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deadline template catalog (009-D18, T-10): per-org templates
     * whose versions carry the rule content. Deliberately distinct
     * from 007's document_templates (merge-field stationery) in
     * table, model, routes, and names.
     */
    public function up(): void
    {
        Schema::create('deadline_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->string('name');
            $table->string('jurisdiction_label')->nullable();
            $table->boolean('demonstration')->default(true);
            $table->string('owner_label')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['org_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_templates');
    }
};
