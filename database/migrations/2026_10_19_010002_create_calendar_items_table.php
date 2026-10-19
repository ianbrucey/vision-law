<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->string('type', 30);
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->string('visibility', 30)->default('matter');
            $table->string('status', 30)->default('draft');
            $table->string('task_status', 30)->nullable();
            $table->string('classification', 30)->nullable();
            $table->string('jurisdiction')->nullable();
            $table->date('due_date')->nullable();
            $table->time('cutoff_time')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone')->default('UTC');
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users');
            $table->foreignUuid('escalation_owner_id')->nullable()->constrained('users');
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users');
            $table->integer('version')->default(1);
            $table->uuid('supersedes_id')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->string('source', 30)->default('manual');
            $table->uuid('template_version_id')->nullable();
            $table->string('rule_key')->nullable();
            $table->jsonb('inputs')->nullable();
            $table->jsonb('calc_trace')->nullable();
            $table->uuid('series_id')->nullable();
            $table->date('occurrence_date')->nullable();
            $table->jsonb('recurrence_rule')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['matter_id', 'due_date']);
            $table->index(['org_id', 'status']);
            $table->index('series_id');
        });

        // Self-referencing FKs must be added after the table (and its PK)
        // exists — Laravel emits FKs as separate ALTERs.
        Schema::table('calendar_items', function (Blueprint $table) {
            $table->foreign('supersedes_id')->references('id')->on('calendar_items');
            $table->foreign('superseded_by_id')->references('id')->on('calendar_items');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE calendar_items ADD CONSTRAINT calendar_items_type_check
            CHECK (type IN ('deadline', 'event', 'task'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE calendar_items ADD CONSTRAINT calendar_items_visibility_check
            CHECK (visibility IN ('matter', 'restricted_audience'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE calendar_items ADD CONSTRAINT calendar_items_status_check
            CHECK (status IN ('draft', 'confirmed', 'at_risk', 'completed', 'superseded', 'canceled'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE calendar_items ADD CONSTRAINT calendar_items_task_status_check
            CHECK (task_status IS NULL OR task_status IN ('not_started', 'in_progress', 'blocked', 'in_review', 'completed', 'canceled'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE calendar_items ADD CONSTRAINT calendar_items_classification_check
            CHECK (classification IS NULL OR classification IN ('hard', 'soft', 'internal'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_items');
    }
};
