<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deadline template versions (009-D18, T-10): versioned rule
     * content with a draft → pending_review → published → retired
     * lifecycle and maker-checker publication (enforced in
     * TemplateService). A published version is immutable — edits fork
     * a new draft version.
     */
    public function up(): void
    {
        Schema::create('deadline_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('template_id')->constrained('deadline_templates')->cascadeOnDelete();
            $table->integer('version');
            $table->string('status', 30)->default('draft');
            $table->jsonb('rules')->default(DB::raw("'[]'::jsonb"));
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['template_id', 'version']);
            $table->index(['template_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deadline_template_versions ADD CONSTRAINT deadline_template_versions_status_check
            CHECK (status IN ('draft', 'pending_review', 'published', 'retired'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_template_versions');
    }
};
