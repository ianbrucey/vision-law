<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;

/*
 * Vision Law formatting helpers — the only door for dates, times, and money
 * in views (docs/UI_Standards.md). T-01 stubs: real implementations land with
 * the first feature that needs them.
 */

if (! function_exists('fmtDate')) {
    /**
     * Format a date for display (e.g. "Oct 7, 2026").
     */
    function fmtDate(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value);

        return $date->format('M j, Y');
    }
}

if (! function_exists('fmtDT')) {
    /**
     * Format a date+time for display (e.g. "Oct 7, 2026 1:00 PM").
     */
    function fmtDT(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value);

        return $date->format('M j, Y g:i A');
    }
}

if (! function_exists('fmtRelative')) {
    /**
     * Relative time for display (e.g. "2 hours ago"). 05-ui.md (spec 006):
     * timestamps render absolute + relative; this is the relative half —
     * always paired with fmtDate()/fmtDT(), never alone.
     */
    function fmtRelative(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        return $date->diffForHumans();
    }
}

if (! function_exists('money')) {
    /**
     * Format a monetary amount for display (e.g. "$1,234.56").
     */
    function money(int|float|string $amount, string $currency = 'USD'): string
    {
        $symbol = $currency === 'USD' ? '$' : $currency.' ';

        return $symbol.number_format((float) $amount, 2);
    }
}
