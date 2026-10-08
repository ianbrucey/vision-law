<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 007 T-04 (editor + templates, stationery).
     *
     * documents: link generated documents back to their source template
     * (template_id + the template version used) and hold the ephemeral
     * editor draft (draft_content / draft_updated_at) — drafts are NOT
     * versions.
     *
     * document_renditions: per-version generated renditions. The editor
     * publishes authored v1 as structured HTML plus a generated PDF
     * rendition (LibreOffice headless); the rendition hangs off the
     * version, not the document, so each version keeps its own PDF.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->integer('template_version')->nullable();
            $table->text('draft_content')->nullable();
            $table->timestampTz('draft_updated_at')->nullable();
        });

        Schema::create('document_renditions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->text('kind');
            $table->foreignUuid('blob_id')->constrained('document_blobs');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['version_id', 'kind']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_renditions ADD CONSTRAINT document_renditions_kind_check
            CHECK (kind IN ('pdf'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_renditions');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['template_id']);
            $table->dropColumn(['template_id', 'template_version', 'draft_content', 'draft_updated_at']);
        });
    }
};
