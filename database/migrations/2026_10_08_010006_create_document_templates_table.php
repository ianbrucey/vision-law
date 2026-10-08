<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Template library (007, DOC-22/23, 007-D03): org-level HTML stationery
     * with {{merge_field}} placeholders and typed field definitions.
     * Merge-field stationery ONLY — no drafting intelligence.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->text('name');
            $table->text('body_html');
            $table->jsonb('field_definitions')->default(DB::raw("'[]'::jsonb"));
            $table->integer('version')->default(1);
            $table->text('status')->default('draft');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['org_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_templates ADD CONSTRAINT document_templates_status_check
            CHECK (status IN ('draft', 'published'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
