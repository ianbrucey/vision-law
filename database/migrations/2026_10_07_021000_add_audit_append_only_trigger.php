<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 001-D11: REVOKE is owner-ineffective in Postgres (the migrator/app
        // role owns audit_events and owners bypass REVOKE). A BEFORE
        // UPDATE/DELETE trigger fires for the owner too, giving genuine
        // DB-level append-only enforcement per PLT-13 / 001-D04.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only (001-D11)';
            END;
            $$;
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_no_update ON audit_events');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_audit_no_update
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_no_update ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_mutation()');
    }
};
