<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('item_id')->constrained('calendar_items')->cascadeOnDelete();
            $table->foreignUuid('policy_id')->nullable()->constrained('reminder_policies')->nullOnDelete();
            $table->string('kind', 30)->default('reminder');
            $table->foreignUuid('recipient_user_id')->constrained('users');
            $table->string('recipient_email')->nullable();
            $table->string('channel', 30);
            $table->string('idempotency_key')->unique();
            $table->timestampTz('scheduled_for');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('status', 40)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('provider_ref')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users');
            $table->timestampTz('snoozed_until')->nullable();
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['status', 'scheduled_for']);
            $table->index('item_id');
            $table->index('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
    }
};
