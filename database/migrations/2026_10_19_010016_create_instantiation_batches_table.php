<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Template instantiation batches (009 T-12, 009-D20).
     *
     * One batch = one confirmed instantiation of a published template
     * version into a matter. The (matter_id, idempotency_key) unique
     * pair is the idempotency law: confirming twice with the same key
     * returns the existing batch and creates nothing new.
     *
     * Also adds the two CalendarItem columns instantiation needs:
     * instantiation_batch_id (the batch that generated the item) and
     * date_overridden (the item's date was manually changed after
     * generation — recalculation preserves such items, 009-D21).
     * calc_trace, template_version_id, rule_key, inputs and the
     * supersedes linkage already exist on calendar_items (T-01).
     */
    public function up(): void
    {
        Schema::create('instantiation_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('template_version_id')->constrained('deadline_template_versions');
            $table->string('idempotency_key');
            $table->jsonb('inputs')->default(DB::raw("'{}'::jsonb"));
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['matter_id', 'idempotency_key']);
            $table->index(['matter_id', 'created_at']);
        });

        Schema::table('calendar_items', function (Blueprint $table) {
            $table->foreignUuid('instantiation_batch_id')->nullable()->constrained('instantiation_batches');
            $table->boolean('date_overridden')->default(false);

            $table->index('instantiation_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instantiation_batch_id');
            $table->dropColumn('date_overridden');
        });

        Schema::dropIfExists('instantiation_batches');
    }
};
