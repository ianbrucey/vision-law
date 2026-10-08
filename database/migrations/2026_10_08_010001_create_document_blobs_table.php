<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content-addressed blob store (007, DOC-01/DOC-18). One row per unique
     * byte sequence; documents' versions reference blobs, never raw paths.
     */
    public function up(): void
    {
        Schema::create('document_blobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('sha256', 64)->unique();
            $table->bigInteger('size');
            $table->text('mime_sniffed');
            $table->text('storage_path');
            $table->integer('refcount')->default(0);
            $table->boolean('quarantined')->default(false);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_blobs');
    }
};
