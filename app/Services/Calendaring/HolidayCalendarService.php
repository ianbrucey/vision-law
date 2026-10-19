<?php

namespace App\Services\Calendaring;

use App\Models\HolidayCalendar;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Holiday / business-day math over the versioned packs (009-D05,
 * T-11). Reads the loaded holiday_calendars / holiday_dates tables;
 * never writes. All dates are date-only (Y-m-d) — time zones never
 * shift a legal due date (009-D23).
 *
 * A "business day" is Monday–Friday and not a holiday in the given
 * pack (when a pack is given). A "court day" is the same count taken
 * against a court holiday pack — see DeadlineEngine.
 */
class HolidayCalendarService
{
    /**
     * Per-instance cache: pack key => (date string => holiday name)
     * for the pack's latest version.
     *
     * @var array<string, array<string, string>>
     */
    private array $dateMaps = [];

    /**
     * Per-instance cache: pack key => loaded pack version (or null).
     *
     * @var array<string, string|null>
     */
    private array $versions = [];

    /**
     * The latest loaded version string for a pack key, or null when
     * the pack is not loaded.
     */
    public function packVersion(string $packKey): ?string
    {
        $this->loadPack($packKey);

        return $this->versions[$packKey] ?? null;
    }

    public function isHoliday(string $packKey, string|DateTimeInterface $date): bool
    {
        return $this->holidayName($packKey, $date) !== null;
    }

    public function holidayName(string $packKey, string|DateTimeInterface $date): ?string
    {
        $this->loadPack($packKey);

        return $this->dateMaps[$packKey][$this->dateString($date)] ?? null;
    }

    public function isWeekend(string|DateTimeInterface $date): bool
    {
        return Carbon::parse($this->dateString($date))->isWeekend();
    }

    /**
     * Monday–Friday and not a holiday in $packKey (when given).
     */
    public function isBusinessDay(string|DateTimeInterface $date, ?string $packKey = null): bool
    {
        $day = Carbon::parse($this->dateString($date));

        if ($day->isWeekend()) {
            return false;
        }

        return $packKey === null || ! $this->isHoliday($packKey, $day->toDateString());
    }

    /**
     * Add $n business days to $date. The start day is day 0 and is
     * never counted (009-D19 trigger-day law): each step moves one
     * calendar day in the direction of $n and counts it only when it
     * is a business day. $n = 0 returns the date unchanged; negative
     * $n counts backwards.
     */
    public function addBusinessDays(string|DateTimeInterface $date, int $n, ?string $packKey = null): string
    {
        $day = Carbon::parse($this->dateString($date));

        if ($n === 0) {
            return $day->toDateString();
        }

        $step = $n > 0 ? 1 : -1;
        $remaining = abs($n);

        while ($remaining > 0) {
            $day = $step > 0 ? $day->copy()->addDay() : $day->copy()->subDay();

            if ($this->isBusinessDay($day->toDateString(), $packKey)) {
                $remaining--;
            }
        }

        return $day->toDateString();
    }

    /**
     * Adjust-forward: the date itself when it is a business day,
     * otherwise the next business day after it (weekends and pack
     * holidays skipped).
     */
    public function nextBusinessDay(string|DateTimeInterface $date, ?string $packKey = null): string
    {
        $day = Carbon::parse($this->dateString($date));

        while (! $this->isBusinessDay($day->toDateString(), $packKey)) {
            $day = $day->copy()->addDay();
        }

        return $day->toDateString();
    }

    /**
     * Adjust-backward counterpart of nextBusinessDay().
     */
    public function previousBusinessDay(string|DateTimeInterface $date, ?string $packKey = null): string
    {
        $day = Carbon::parse($this->dateString($date));

        while (! $this->isBusinessDay($day->toDateString(), $packKey)) {
            $day = $day->copy()->subDay();
        }

        return $day->toDateString();
    }

    private function loadPack(string $packKey): void
    {
        if (array_key_exists($packKey, $this->dateMaps)) {
            return;
        }

        $calendar = HolidayCalendar::query()
            ->where('key', $packKey)
            ->orderByDesc('version')
            ->first();

        if ($calendar === null) {
            $this->dateMaps[$packKey] = [];
            $this->versions[$packKey] = null;

            return;
        }

        $map = [];
        foreach ($calendar->dates()->get() as $row) {
            $map[$row->date->toDateString()] = (string) $row->name;
        }

        $this->dateMaps[$packKey] = $map;
        $this->versions[$packKey] = (string) $calendar->version;
    }

    private function dateString(string|DateTimeInterface $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return Carbon::instance($date)->toDateString();
        }

        return Carbon::parse($date)->toDateString();
    }
}
