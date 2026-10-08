<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 007 T-03 (DOC-04): columns backing technical metadata
     * extraction.
     *
     * - documents.metadata (jsonb): extracted technical metadata —
     *   dimensions, DPI, document properties, extraction notes. Mutable;
     *   lives on the document row, not the immutable version row.
     * - documents.needs_ocr: native-text vs image-only detection result;
     *   feeds the T-06 OCR pipeline.
     * - document_versions.original_filename: the client-supplied filename
     *   at ingest, per version, for download Content-Disposition (C-06).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->jsonb('metadata')->nullable();
            $table->boolean('needs_ocr')->default(false);
        });

        Schema::table('document_versions', function (Blueprint $table): void {
            $table->text('original_filename')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('original_filename');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['metadata', 'needs_ocr']);
        });
    }
};
