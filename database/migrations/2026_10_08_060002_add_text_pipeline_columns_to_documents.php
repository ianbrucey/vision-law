<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 007 T-06 (DOC-15/16/17): text extraction/OCR pipeline state.
     *
     * - documents.processing_stage: the T-06 pipeline stage for the
     *   document's current version. Stages: processing (pipeline started),
     *   extracting (native text extraction running), needs_ocr (image-only
     *   content queued for OCR — mirrors documents.needs_ocr at the
     *   pipeline level), ocr_processing (Tesseract running), indexed
     *   (per-page text rows written, tsvector populated), partial
     *   (extraction/OCR failed or yielded nothing — the document stays
     *   usable, never silently marked clean/ready). NULL = the pipeline
     *   never ran (rows predating T-06).
     *
     *   This is deliberately a SEPARATE column from documents.status:
     *   the status rollup (processing/ready/quarantined/trash) stays owned
     *   by T-02/T-05/T-09, and per-version scanning/extracting stages stay
     *   on the DB-immutable document_versions row (007-D06 — set at
     *   insert, never updated).
     *
     * - documents.ocr_cost: jsonb log of per-page OCR cost data
     *   {provider, tesseract_version, lang, dpi, pages, per_page_ms,
     *   total_ms, estimated_cost_usd}. Native Tesseract = $0; the provider
     *   interface (App\Services\Ocr\OcrProvider) leaves the door open for
     *   a paid provider later.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('processing_stage')->nullable();
            $table->jsonb('ocr_cost')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_processing_stage_check
            CHECK (processing_stage IN (
                'processing', 'extracting', 'needs_ocr',
                'ocr_processing', 'indexed', 'partial'
            ))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_processing_stage_check');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['processing_stage', 'ocr_cost']);
        });
    }
};
