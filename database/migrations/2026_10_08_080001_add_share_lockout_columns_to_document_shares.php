<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password-attempt lockout state for external share links (007 T-08,
     * DOC-25). 5 wrong passwords → 15-minute lockout; both counters live
     * on the row so the lockout survives process restarts (no cache
     * dependency for a security control).
     */
    public function up(): void
    {
        Schema::table('document_shares', function (Blueprint $table): void {
            $table->integer('failed_password_attempts')->default(0);
            $table->timestampTz('password_locked_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_shares', function (Blueprint $table): void {
            $table->dropColumn(['failed_password_attempts', 'password_locked_until']);
        });
    }
};
