<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retention policies (007, DOC-27/28). retention_period is a native
     * Postgres interval (e.g. '7 years'); Laravel's Blueprint has no
     * interval column type, so it is added raw after table creation.
     * Policies are versioned; only active ones drive nightly flagging.
     */
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->text('name');
            $table->text('category');
            $table->text('matter_type')->nullable();
            // retention_period interval added raw below.
            $table->text('trigger');
            $table->text('disposition');
            $table->text('legal_basis');
            $table->integer('version')->default(1);
            $table->text('status')->default('draft');
            $table->timestampsTz();

            $table->index(['org_id', 'status']);
        });

        DB::statement('ALTER TABLE retention_policies ADD COLUMN retention_period interval NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_trigger_check
            CHECK (trigger IN ('matter_close', 'document_date', 'fixed_date'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_disposition_check
            CHECK (disposition IN ('destroy', 'review', 'archive'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_status_check
            CHECK (status IN ('draft', 'active'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The interval column dies with the table.
        Schema::dropIfExists('retention_policies');
    }
};
