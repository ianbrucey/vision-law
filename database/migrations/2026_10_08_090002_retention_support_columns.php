<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 007 T-09 support columns (additive only):
     *
     * - documents.category: free-text records category a retention policy
     *   matches against (e.g. 'correspondence', 'pleading').
     * - documents.retention_extended_until: an 'extend' disposition pushes
     *   re-flagging out to this date; the nightly evaluation skips
     *   documents whose extension is still in the future.
     * - retention_policies.fixed_date: the anchor date for 'fixed_date'
     *   trigger policies (e.g. a regulatory cutoff).
     * - document_blobs.archived: disposition-archive moves bytes to the
     *   cold prefix and sets this flag (preview disabled).
     * - documents.status gains 'archived'; retention_policies.status gains
     *   'superseded' (exactly one active version per org+name).
     * - disposition_queue.requested_by becomes nullable: nightly
     *   auto-queueing is system-initiated, not human-initiated.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('category')->nullable();
            $table->timestampTz('retention_extended_until')->nullable();
            $table->index('category');
        });

        Schema::table('retention_policies', function (Blueprint $table): void {
            $table->timestampTz('fixed_date')->nullable();
        });

        Schema::table('document_blobs', function (Blueprint $table): void {
            $table->boolean('archived')->default(false);
            $table->index('archived');
        });

        // System-initiated (nightly) queue rows have no human requester.
        // Raw ALTER: avoids the doctrine/dbal dependency a ->change()
        // would require.
        DB::statement('ALTER TABLE disposition_queue ALTER COLUMN requested_by DROP NOT NULL');

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_status_check
            CHECK (status IN ('processing', 'ready', 'quarantined', 'trash', 'archived'))
            SQL);

        DB::statement('ALTER TABLE retention_policies DROP CONSTRAINT retention_policies_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_status_check
            CHECK (status IN ('draft', 'active', 'superseded'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_status_check
            CHECK (status IN ('processing', 'ready', 'quarantined', 'trash'))
            SQL);

        DB::statement('ALTER TABLE retention_policies DROP CONSTRAINT retention_policies_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_status_check
            CHECK (status IN ('draft', 'active'))
            SQL);

        // Only safe when no system-initiated rows exist; the queue is
        // ephemeral by design.
        DB::statement('ALTER TABLE disposition_queue ALTER COLUMN requested_by SET NOT NULL');

        Schema::table('document_blobs', function (Blueprint $table): void {
            $table->dropIndex(['archived']);
            $table->dropColumn('archived');
        });

        Schema::table('retention_policies', function (Blueprint $table): void {
            $table->dropColumn('fixed_date');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn(['category', 'retention_extended_until']);
        });
    }
};
