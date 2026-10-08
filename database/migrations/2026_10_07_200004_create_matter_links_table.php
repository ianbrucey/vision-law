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
        Schema::create('matter_links', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('org_id')->constrained('organizations');
            $table->foreignUuid('matter_id')->constrained('matters');
            $table->foreignUuid('related_matter_id')->constrained('matters');
            $table->string('link_type', 30);
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('matter_id');
            $table->index('related_matter_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE matter_links ADD CONSTRAINT matter_links_link_type_check
            CHECK (link_type IN (
                'same_client', 'consolidated', 'appeal', 'companion', 'other'
            ))
            SQL);

        // No self-links; one row per unordered pair per org. The canonical
        // ordering (matter_id < related_matter_id) is service-enforced.
        DB::statement('ALTER TABLE matter_links ADD CONSTRAINT matter_links_no_self_link CHECK (matter_id <> related_matter_id)');
        DB::statement('CREATE UNIQUE INDEX matter_links_org_pair_unique ON matter_links (org_id, matter_id, related_matter_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Indexes and CHECK constraints die with the table.
        Schema::dropIfExists('matter_links');
    }
};
