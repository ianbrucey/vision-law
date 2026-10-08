<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Domain service for matters (spec 006). Seeded in T-01 with number
 * generation only — T-02 adds create/transition/close, T-04 adds
 * assignment/search/summary. Controllers never write matter tables
 * directly; they go through this service.
 */
class MatterService
{
    /**
     * Next MAT-YYYY-NNNN number for an org+year (006-D01, 006-D10).
     *
     * Race-safe: the MAX+1 computation runs inside a transaction holding
     * pg_advisory_xact_lock(hashtext(org_id), year), so concurrent creators
     * serialize on the lock and can never compute the same number. Numbers
     * are never reused — soft-deleted rows still occupy their numbers.
     * UNIQUE(org_id, matter_number) is the backstop.
     */
    public static function generateNumber(string $orgId, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($orgId, $year): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), ?)', [$orgId, $year]);

            $max = (int) DB::table('matters')
                ->where('org_id', $orgId)
                ->where('matter_number', 'like', "MAT-{$year}-%")
                ->selectRaw("MAX(substring(matter_number from '-(\\d+)$')::int) AS max_n")
                ->value('max_n');

            return sprintf('MAT-%d-%04d', $year, $max + 1);
        });
    }
}
