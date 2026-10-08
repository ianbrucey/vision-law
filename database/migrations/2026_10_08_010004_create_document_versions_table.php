<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable version rows (007, DOC-18/20, 007-D06). Every content
     * change is a new row with a gapless version_number per document;
     * history is never rewritten. DB-level immutability uses a BEFORE
     * UPDATE/DELETE trigger — the same pattern 001 used for audit_events
     * (001-D11), because REVOKE is owner-ineffective in Postgres.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->integer('version_number');
            $table->foreignUuid('blob_id')->constrained('document_blobs')->restrictOnDelete();
            $table->text('change_note')->nullable();
            $table->uuid('restored_from_version_id')->nullable();
            $table->text('processing_status');
            $table->integer('page_count')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            // No updated_at — immutable by design.
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'version_number']);
            $table->index(['document_id', 'version_number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions ADD CONSTRAINT document_versions_processing_status_check
            CHECK (processing_status IN ('pending', 'scanning', 'extracting', 'ocr', 'ready', 'failed'))
            SQL);

        // Self-referencing FK in its own statement (same SQLSTATE 42830
        // lesson as 006's matter_comments migration).
        Schema::table('document_versions', function (Blueprint $table) {
            $table->foreign('restored_from_version_id')
                ->references('id')->on('document_versions')
                ->nullOnDelete();
        });

        // Now that the table exists, wire documents.current_version_id to it.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('document_versions')
                ->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_document_version_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'document_versions is immutable (007-D06)';
            END;
            $$;
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_document_versions_immutable ON document_versions');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_document_versions_immutable
            BEFORE UPDATE OR DELETE ON document_versions
            FOR EACH ROW EXECUTE FUNCTION prevent_document_version_mutation()
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_document_versions_immutable ON document_versions');
        DB::statement('DROP FUNCTION IF EXISTS prevent_document_version_mutation()');

        // Drop the FK from documents before the referenced table goes away.
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('document_versions');
    }
};
