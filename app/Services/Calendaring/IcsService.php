<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * RFC 5545 iCalendar generation (009 T-14, 009-D22/D23).
 *
 * Every multi-item surface (auth export, per-user feed) is built
 * from AgendaQuery's authorized scope for the relevant user —
 * callers never hand this service unscoped items for those
 * surfaces. Timed items emit UTC instants (DTSTAMP/DTSTART/DTEND
 * with Z); date-only items emit VALUE=DATE pairs. UID is stable
 * per item and SEQUENCE tracks the item version, so invite
 * update/cancel cycles stay one calendar object (C-17).
 */
class IcsService
{
    public const UID_DOMAIN = 'vision.justicequest.pro';

    /** Statuses that appear on external surfaces. */
    private const EXTERNAL_STATUSES = ['confirmed', 'at_risk'];

    /**
     * The caller's authorized items for the ICS export surface.
     */
    public static function exportForUser(User $user): string
    {
        return self::calendar(self::authorizedItems($user));
    }

    /**
     * The feed owner's authorized items, re-scoped at request time:
     * access revoked since the feed was issued simply drops out.
     */
    public static function feedForUser(User $user): string
    {
        return self::calendar(self::authorizedItems($user));
    }

    /**
     * @return Collection<int, CalendarItem>
     */
    public static function authorizedItems(User $user): Collection
    {
        return AgendaQuery::scopedQuery($user)
            ->whereIn('calendar_items.status', self::EXTERNAL_STATUSES)
            ->with(['matter', 'assignee'])
            ->orderByRaw('coalesce(calendar_items.due_date, calendar_items.occurrence_date, calendar_items.starts_at::date) asc nulls last')
            ->orderBy('calendar_items.title')
            ->get();
    }

    /**
     * A single-item invite calendar: REQUEST for create/update,
     * CANCEL for cancellation, always the same UID with the
     * caller-supplied (rising) SEQUENCE.
     */
    public static function invite(CalendarItem $item, string $method = 'REQUEST', ?int $sequence = null): string
    {
        $method = strtoupper($method) === 'CANCEL' ? 'CANCEL' : 'REQUEST';

        return self::calendar(new Collection([$item]), $method, $sequence);
    }

    /**
     * @param  Collection<int, CalendarItem>  $items
     */
    public static function calendar(Collection $items, ?string $method = null, ?int $sequenceOverride = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Vision Law//Calendaring 009//EN',
            'CALSCALE:GREGORIAN',
        ];

        if ($method !== null) {
            $lines[] = 'METHOD:'.$method;
        }

        foreach ($items as $item) {
            foreach (self::eventLines($item, $method, $sequenceOverride) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        $out = '';
        foreach ($lines as $line) {
            foreach (self::fold($line) as $physical) {
                $out .= $physical."\r\n";
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function eventLines(CalendarItem $item, ?string $method, ?int $sequenceOverride): array
    {
        $lines = ['BEGIN:VEVENT'];
        $lines[] = 'UID:'.(string) $item->getKey().'@'.self::UID_DOMAIN;
        $lines[] = 'DTSTAMP:'.now()->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
        $lines[] = 'SEQUENCE:'.($sequenceOverride ?? (int) $item->version);
        $lines[] = 'SUMMARY:'.self::escapeText((string) $item->title);

        if ($item->starts_at !== null) {
            $start = Carbon::instance($item->starts_at)->setTimezone('UTC');
            $lines[] = 'DTSTART:'.$start->format('Ymd\THis\Z');
            $end = $item->ends_at !== null
                ? Carbon::instance($item->ends_at)->setTimezone('UTC')
                : $start->copy()->addHour();
            $lines[] = 'DTEND:'.$end->format('Ymd\THis\Z');
        } elseif ($item->due_date !== null || $item->occurrence_date !== null) {
            $date = ($item->due_date ?? $item->occurrence_date)->copy();
            $lines[] = 'DTSTART;VALUE=DATE:'.$date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$date->copy()->addDay()->format('Ymd');
        }

        if ($method === 'CANCEL' || $item->status === 'canceled') {
            $lines[] = 'STATUS:CANCELLED';
        }

        if ($item->location !== null && $item->location !== '') {
            $lines[] = 'LOCATION:'.self::escapeText((string) $item->location);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * RFC 5545 §3.3.11 text escaping.
     */
    public static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * Fold a content line at 75 octets (RFC 5545 §3.1); continuation
     * lines start with a single space.
     *
     * @return list<string>
     */
    private static function fold(string $line): array
    {
        if (strlen($line) <= 75) {
            return [$line];
        }

        $parts = [substr($line, 0, 75)];
        $rest = substr($line, 75);
        while ($rest !== '') {
            $parts[] = ' '.substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        return $parts;
    }
}
