<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-page extracted/OCR text, linked to the immutable version
     * (spec 007, 02-schema-delta.md). Written idempotently per version by
     * the T-06 extraction/OCR pipeline; read by T-07's structured diff
     * (DOC-21) and T-06's full-text search (DOC-15/17).
     *
     * text_tsv is maintained by a trigger so the GIN index stays correct
     * without writer discipline.
     */
    public function up(): void
    {
        Schema::create('document_text_pages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->integer('page_number');
            $table->text('text');
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->jsonb('ocr_words')->nullable(); // per-word OCR confidences: [{t: word, c: 0..1}] (DOC-16)
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['version_id', 'page_number']);
        });

        DB::statement('ALTER TABLE document_text_pages ADD COLUMN text_tsv tsvector');
        DB::statement('CREATE INDEX document_text_pages_text_tsv_gin ON document_text_pages USING GIN (text_tsv)');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_document_text_pages_tsv
            BEFORE INSERT OR UPDATE OF text ON document_text_pages
            FOR EACH ROW EXECUTE FUNCTION tsvector_update_trigger(text_tsv, 'pg_catalog.english', text)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_text_pages');
    }
};
