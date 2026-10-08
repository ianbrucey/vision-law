<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matter_document_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->string('direction', 10);
            $table->string('counterparty', 255);
            $table->timestampTz('logged_at');
            $table->string('method', 30);
            $table->text('notes')->nullable();
            $table->jsonb('annotations')->default(DB::raw("'{}'::jsonb"));
            // Append-only by design: no updated_at. Corrections are new rows.
            $table->timestampTz('created_at')->useCurrent();

            $table->index('matter_id');
            $table->index(['matter_id', 'logged_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE matter_document_log ADD CONSTRAINT matter_document_log_direction_check
            CHECK (direction IN ('received', 'sent'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE matter_document_log ADD CONSTRAINT matter_document_log_method_check
            CHECK (method IN ('upload', 'email', 'share_link', 'integration'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The CHECK constraints die with the table.
        Schema::dropIfExists('matter_document_log');
    }
};
