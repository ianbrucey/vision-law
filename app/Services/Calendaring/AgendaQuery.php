<?php

namespace App\Services\Calendaring;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\Matter;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AgendaQuery
{
    public const MODES = ['day', 'week', 'month', 'list'];

    /**
     * Authorized agenda for a user.
     *
     * @param  array<string, mixed>  $filters  matter_ids, types, statuses, assignee_id, search
     * @return array{
     *     mode: string,
     *     anchor: string,
     *     start: string|null,
     *     end: string|null,
     *     viewer_timezone: string,
     *     items: Collection<int, CalendarItem>,
     *     groups: array<string, Collection<int, CalendarItem>>,
     *     counts: array{total: int, by_status: array<string, int>, by_type: array<string, int>}
     * }
     */
    public static function forUser(User $user, string $mode = 'list', ?string $anchor = null, array $filters = [], ?string $viewerTimezone = null): array
    {
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'list';
        }

        // 009-D23 (T-15): timed items group and window by the VIEWER's
        // zone; date-only items keep their stored calendar date in
        // every zone. The viewer zone is the explicit argument, else
        // the user's reminder-preference zone, else UTC.
        $viewerTimezone = self::validTimezone($viewerTimezone) ?? self::viewerTimezoneFor($user);

        $anchorDate = $anchor !== null && $anchor !== '' ? Carbon::parse($anchor)->startOfDay() : now()->startOfDay();

        [$start, $end] = match ($mode) {
            'day' => [$anchorDate->copy(), $anchorDate->copy()],
            'week' => [$anchorDate->copy()->startOfWeek(), $anchorDate->copy()->endOfWeek()],
            'month' => [$anchorDate->copy()->startOfMonth(), $anchorDate->copy()->endOfMonth()],
            default => [null, null],
        };

        $query = self::scopedQuery($user, $filters);

        if ($start !== null && $end !== null) {
            $query->whereRaw(
                'coalesce(calendar_items.due_date, calendar_items.occurrence_date, (calendar_items.starts_at at time zone ?)::date) between ? and ?',
                [$viewerTimezone, $start->toDateString(), $end->toDateString()]
            );
        }

        // Counts derive from the same scoped + windowed + filtered query.
        $countQuery = clone $query;
        $total = (clone $countQuery)->count();
        $byStatus = (clone $countQuery)->selectRaw('calendar_items.status as k, count(*) as c')
            ->groupBy('calendar_items.status')->pluck('c', 'k')->map(fn ($v) => (int) $v)->all();
        $byType = (clone $countQuery)->selectRaw('calendar_items.type as k, count(*) as c')
            ->groupBy('calendar_items.type')->pluck('c', 'k')->map(fn ($v) => (int) $v)->all();

        $items = $query->with(['matter', 'assignee'])
            ->orderByRaw('coalesce(calendar_items.due_date, calendar_items.occurrence_date, (calendar_items.starts_at at time zone ?)::date) asc nulls last', [$viewerTimezone])
            ->orderBy('calendar_items.starts_at')
            ->orderBy('calendar_items.title')
            ->orderBy('calendar_items.id')
            ->get();

        self::annotateChanged($items, $start, $end);

        $groups = [];
        foreach ($items as $item) {
            $key = self::effectiveDate($item, $viewerTimezone) ?? 'undated';
            $item->setAttribute('viewer_date', $key === 'undated' ? null : $key);
            $groups[$key] ??= new Collection;
            $groups[$key]->push($item);
        }
        ksort($groups);

        return [
            'mode' => $mode,
            'anchor' => $anchorDate->toDateString(),
            'start' => $start?->toDateString(),
            'end' => $end?->toDateString(),
            'viewer_timezone' => $viewerTimezone,
            'items' => $items,
            'groups' => $groups,
            'counts' => [
                'total' => $total,
                'by_status' => $byStatus,
                'by_type' => $byType,
            ],
        ];
    }

    /**
     * The single authorized scope every agenda/search/count path uses:
     * matters via Matter::accessibleBy, plus item-level visibility.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<CalendarItem>
     */
    public static function scopedQuery(User $user, array $filters = []): Builder
    {
        $accessibleMatterIds = Matter::query()->accessibleBy($user)->select('matters.id');

        $query = CalendarItem::query()
            ->whereIn('calendar_items.matter_id', $accessibleMatterIds)
            ->where('calendar_items.org_id', $user->org_id)
            ->where(function (Builder $visibility) use ($user): void {
                $visibility->where('calendar_items.visibility', 'matter')
                    ->orWhere(function (Builder $restricted) use ($user): void {
                        $restricted->where('calendar_items.visibility', 'restricted_audience')
                            ->where(function (Builder $audience) use ($user): void {
                                $audience->where('calendar_items.assignee_id', $user->getKey())
                                    ->orWhere('calendar_items.created_by', $user->getKey())
                                    ->orWhereExists(function ($exists) use ($user): void {
                                        $exists->selectRaw('1')->from('item_attendees')
                                            ->whereColumn('item_attendees.item_id', 'calendar_items.id')
                                            ->where('item_attendees.user_id', $user->getKey());
                                    });
                            });
                    });
            });

        if (! empty($filters['matter_ids']) && is_array($filters['matter_ids'])) {
            $ids = array_values(array_filter($filters['matter_ids'], fn ($v) => is_string($v) && $v !== ''));
            if ($ids !== []) {
                $query->whereIn('calendar_items.matter_id', $ids);
            }
        }

        if (! empty($filters['matter_id']) && is_string($filters['matter_id'])) {
            $query->where('calendar_items.matter_id', $filters['matter_id']);
        }

        if (! empty($filters['types']) && is_array($filters['types'])) {
            $types = array_values(array_intersect($filters['types'], CalendarItem::TYPES));
            if ($types !== []) {
                $query->whereIn('calendar_items.type', $types);
            }
        } elseif (! empty($filters['type']) && is_string($filters['type'])) {
            $query->where('calendar_items.type', $filters['type']);
        }

        if (! empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statuses = array_values(array_intersect($filters['statuses'], CalendarItem::STATUSES));
            if ($statuses !== []) {
                $query->whereIn('calendar_items.status', $statuses);
            }
        } elseif (! empty($filters['status']) && is_string($filters['status'])) {
            $query->where('calendar_items.status', $filters['status']);
        }

        if (! empty($filters['assignee_id']) && is_string($filters['assignee_id'])) {
            $query->where('calendar_items.assignee_id', $filters['assignee_id']);
        }

        $search = $filters['search'] ?? $filters['q'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function (Builder $s) use ($term): void {
                $s->where('calendar_items.title', 'ilike', $term)
                    ->orWhere('calendar_items.description', 'ilike', $term);
            });
        }

        return $query;
    }

    public static function effectiveDate(CalendarItem $item, ?string $viewerTimezone = null): ?string
    {
        // Date-only values are zone-stable by law (009-D23): they are
        // returned verbatim for every viewer zone.
        if ($item->due_date !== null) {
            return $item->due_date->toDateString();
        }
        if ($item->occurrence_date !== null) {
            return $item->occurrence_date->toDateString();
        }
        if ($item->starts_at !== null) {
            $tz = self::validTimezone($viewerTimezone) ?? 'UTC';

            return Carbon::instance($item->starts_at)->setTimezone($tz)->toDateString();
        }

        return null;
    }

    /**
     * The zone an agenda renders in for a user: their reminder
     * preference zone when set, else UTC.
     */
    public static function viewerTimezoneFor(User $user): string
    {
        $pref = ReminderPreference::query()->where('user_id', $user->getKey())->first();
        $tz = $pref !== null ? self::validTimezone($pref->timezone) : null;

        return $tz ?? 'UTC';
    }

    private static function validTimezone(?string $tz): ?string
    {
        if ($tz === null || $tz === '') {
            return null;
        }

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : null;
    }

    /**
     * Annotate each item with changed / changed_from / changed_to from the
     * audit trail: a status-changing calendar.item.* event whose
     * created_at falls in the window (any time for list mode).
     *
     * @param  Collection<int, CalendarItem>  $items
     */
    private static function annotateChanged(Collection $items, ?Carbon $start, ?Carbon $end): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $ids = $items->map(fn (CalendarItem $i) => (string) $i->getKey())->all();

        $events = AuditEvent::query()
            ->whereIn('matter_id', $items->map(fn (CalendarItem $i) => (string) $i->matter_id)->unique()->all())
            ->where('event', 'like', 'calendar.item.%')
            ->when($start !== null, fn ($q) => $q->where('created_at', '>=', $start->copy()->startOfDay()))
            ->when($end !== null, fn ($q) => $q->where('created_at', '<=', $end->copy()->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<string, array{from: mixed, to: mixed}> $latestByItem */
        $latestByItem = [];
        foreach ($events as $event) {
            $payloadRaw = $event->getAttribute('payload');
            /** @var array<string, mixed> $payload */
            $payload = is_array($payloadRaw) ? $payloadRaw : [];
            $itemId = isset($payload['item_id']) ? (string) $payload['item_id'] : null;
            if ($itemId === null || ! in_array($itemId, $ids, true) || isset($latestByItem[$itemId])) {
                continue;
            }
            $beforeArr = isset($payload['before']) && is_array($payload['before']) ? $payload['before'] : [];
            $afterArr = isset($payload['after']) && is_array($payload['after']) ? $payload['after'] : [];
            $before = $beforeArr['status'] ?? null;
            $after = $afterArr['status'] ?? null;
            if ($before !== null && $after !== null && $before !== $after) {
                $latestByItem[$itemId] = ['from' => $before, 'to' => $after];
            }
        }

        foreach ($items as $item) {
            $change = $latestByItem[(string) $item->getKey()] ?? null;
            $item->setAttribute('changed', $change !== null);
            $item->setAttribute('changed_from', $change !== null ? $change['from'] : null);
            $item->setAttribute('changed_to', $change !== null ? $change['to'] : null);
        }
    }
}
