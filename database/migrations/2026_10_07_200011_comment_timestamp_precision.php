<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unread-comment counting (MAT-16) compares comment created_at against
     * the reader's last_read_at. At second precision a comment posted in
     * the same second as the timeline read is silently dropped from the
     * unread count, so both columns move to microsecond precision
     * (006-D15).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE matter_comments ALTER COLUMN created_at TYPE timestamptz(6)');
        DB::statement('ALTER TABLE matter_comment_reads ALTER COLUMN last_read_at TYPE timestamptz(6)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE matter_comment_reads ALTER COLUMN last_read_at TYPE timestamptz(0)');
        DB::statement('ALTER TABLE matter_comments ALTER COLUMN created_at TYPE timestamptz(0)');
    }
};
