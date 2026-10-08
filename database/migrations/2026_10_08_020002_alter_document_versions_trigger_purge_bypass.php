<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 007-D07: sanctioned purge bypass for the document_versions
     * immutability trigger.
     *
     * 007-D06 makes document_versions DB-immutable, but the scheduled
     * trash purge (007 T-05, DOC-11: soft delete → 30-day trash → hard
     * delete) is a specified destruction path — version rows for a purged
     * document must be removable. The trigger now allows DELETE only when
     * the session GUC `document_versions.purge_allowed` is 'on'. The purge
     * service sets it via SET LOCAL inside its own transaction, so the
     * bypass is scoped to that transaction and unavailable to every other
     * writer (including the app role). Default-deny: an unset GUC reads as
     * NULL and does not match, so all existing behavior is unchanged.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_document_version_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                -- 007-D07: single sanctioned bypass — the T-05 trash purge.
                IF current_setting('document_versions.purge_allowed', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'document_versions is immutable (007-D06)';
            END;
            $$;
        SQL);
    }

    /**
     * Reverse the migrations: restore the unconditional guard.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_document_version_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'document_versions is immutable (007-D06)';
            END;
            $$;
        SQL);
    }
};
