<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        PostgresGrammar::macro('typeCitext', fn (Fluent $column) => 'citext');

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->addColumn('citext', 'email');
            $table->string('token_hash')->unique();
            $table->string('role', 50);
            // FK to matters is attached in the matters migration: per the spec
            // migration order, matters is created after invitations.
            $table->uuid('matter_id')->nullable();
            $table->foreignUuid('invited_by')->constrained('users');
            $table->timestampTz('expires_at')->default(DB::raw("now() + interval '7 days'"));
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['org_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
