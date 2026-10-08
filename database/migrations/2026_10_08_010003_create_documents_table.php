<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document object (007). One row per logical document; versions
     * hang off document_versions. Exactly one matter (DOC-13).
     *
     * NOTE: current_version_id's FK is added by the document_versions
     * migration (which runs next), since the target table does not exist
     * yet. Its down() drops that FK before dropping document_versions.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            // tags is a Postgres text[] (keyword search, DOC-14); Laravel's
            // Blueprint has no array column type, so it is added raw below.
            $table->text('kind');
            $table->uuid('current_version_id')->nullable();
            $table->text('status');
            $table->text('metadata_status')->default('ok');
            $table->timestampTz('retention_flagged_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['org_id', 'matter_id', 'status']);
            $table->index('current_version_id');
        });

        DB::statement("ALTER TABLE documents ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");

        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_kind_check
            CHECK (kind IN ('uploaded', 'authored', 'generated'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_status_check
            CHECK (status IN ('processing', 'ready', 'quarantined', 'trash'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_metadata_status_check
            CHECK (metadata_status IN ('ok', 'partial'))
            SQL);

        // Trigram keyword search over title + tags (DOC-14).
        // array_to_string is STABLE (conservatively marked), but index
        // expressions require IMMUTABLE functions — the wrapper below is
        // behaviorally immutable (pure, deterministic), so the index is
        // sound. Query-time search uses the same expression.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_array_to_string(arr text[], sep text)
            RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
            AS $$ SELECT array_to_string(arr, sep) $$;
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX documents_title_tags_trgm ON documents
            USING gin ((title || ' ' || coalesce(immutable_array_to_string(tags, ' '), '')) gin_trgm_ops)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // CHECK constraints, indexes, and the tags column die with the
        // table; the immutable wrapper function is dropped explicitly.
        Schema::dropIfExists('documents');
        DB::statement('DROP FUNCTION IF EXISTS immutable_array_to_string(text, text)');
    }
};
