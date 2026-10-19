<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurrenceService
{
    /** Fields an edit-scope request may change on occurrences. */
    private const EDITABLE = [
        'title', 'description', 'due_date', 'occurrence_date', 'starts_at', 'ends_at',
        'location', 'meeting_url', 'classification', 'jurisdiction', 'timezone',
        'assignee_id', 'task_status', 'recurrence_rule',
    ];

    /** Statuses a following/series edit may still touch. */
    private const MUTABLE_STATUSES = ['draft', 'confirmed', 'at_risk'];

    /**
     * Materialize occurrences for a series anchor.
     *
     * Convention: the anchor IS the first occurrence. Its series_id is
     * its own id and its occurrence_date is the series start. Generated
     * rows share that series_id; generation is idempotent per
     * (series_id, occurrence_date) — re-running creates nothing new.
     *
     * @return Collection<int, CalendarItem> all rows of the series, date-ordered
     */
    public static function materialize(CalendarItem $anchor): Collection
    {
        $rule = is_array($anchor->recurrence_rule) ? $anchor->recurrence_rule : [];
        if ($rule === []) {
            return collect([$anchor]);
        }

        $start = self::seriesStart($anchor, $rule);
        if ($start === null) {
            return collect([$anchor]);
        }

        return DB::transaction(function () use ($anchor, $rule, $start): Collection {
            // Reload DB defaults (visibility, all_day) the in-memory
            // anchor may not carry when it was just created.
            if ($anchor->exists) {
                $anchor->refresh();
            }

            // Pin the anchor as occurrence #1 of its own series.
            $updates = [];
            if ((string) ($anchor->series_id ?? '') !== (string) $anchor->getKey()) {
                $updates['series_id'] = (string) $anchor->getKey();
            }
            if ($anchor->occurrence_date === null) {
                $updates['occurrence_date'] = $start->toDateString();
            }
            if ($anchor->due_date === null && $anchor->type !== 'event') {
                $updates['due_date'] = $start->toDateString();
            }
            if ($updates !== []) {
                $anchor->forceFill($updates)->save();
            }

            foreach (self::occurrenceDates($start, $rule) as $date) {
                $dateString = $date->toDateString();
                if ($dateString === $start->toDateString()) {
                    continue; // the anchor itself
                }
                $exists = CalendarItem::query()
                    ->where('series_id', (string) $anchor->getKey())
                    ->where('occurrence_date', $dateString)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $attrs = $anchor->only([
                    'org_id', 'matter_id', 'type', 'title', 'description', 'visibility',
                    'status', 'task_status', 'classification', 'jurisdiction', 'cutoff_time',
                    'all_day', 'timezone', 'location', 'meeting_url', 'assignee_id',
                    'escalation_owner_id', 'reviewer_id', 'template_version_id', 'rule_key',
                    'inputs', 'calc_trace', 'created_by', 'updated_by',
                ]);
                $attrs['series_id'] = (string) $anchor->getKey();
                $attrs['occurrence_date'] = $dateString;
                $attrs['due_date'] = $anchor->due_date !== null ? $dateString : null;
                $attrs['recurrence_rule'] = $rule;
                $attrs['source'] = 'recurrence';
                $attrs['version'] = 1;
                if ($anchor->starts_at !== null) {
                    $deltaDays = (int) $start->diffInDays($date, false);
                    $attrs['starts_at'] = $anchor->starts_at->copy()->addDays($deltaDays)->toIso8601String();
                    $attrs['ends_at'] = $anchor->ends_at?->copy()->addDays($deltaDays)->toIso8601String();
                }

                CalendarItem::create($attrs);
            }

            return CalendarItem::query()
                ->where('series_id', (string) $anchor->getKey())
                ->orderBy('occurrence_date')
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * Apply an edit with scope this|following|series (009-D14).
     *
     * - this: only the target row; it is detached (series_id retained
     *   for provenance, recurrence_detached=true) so later
     *   following/series edits skip it.
     * - following: the target + later occurrences that are still
     *   draft/confirmed/at_risk and not detached.
     * - series: like following, plus the recurrence_rule (when the
     *   changes carry one) is written to the anchor row — the anchor's
     *   other fields are untouched when it is an earlier occurrence.
     *
     * Past (earlier than the target) and completed/canceled/superseded
     * occurrences are never mutated by following/series.
     *
     * @param  array<string, mixed>  $changes
     * @return Collection<int, CalendarItem> the mutated rows
     *
     * @throws ValidationException
     */
    public static function editWithScope(CalendarItem $item, string $scope, array $changes, User $actor): Collection
    {
        AccessControl::authorize($actor, 'edit', $item->matter);

        if (! in_array($scope, ['this', 'following', 'series'], true)) {
            throw ValidationException::withMessages(['scope' => 'Scope must be this, following, or series.']);
        }

        $fields = array_intersect_key($changes, array_flip(self::EDITABLE));
        if ($fields === []) {
            throw ValidationException::withMessages(['changes' => 'No editable fields were provided.']);
        }

        return DB::transaction(function () use ($item, $scope, $fields, $actor): Collection {
            if ($scope === 'this') {
                $updated = self::applyToRow($item, $fields, $actor, $scope, detach: true);

                return collect([$updated]);
            }

            $seriesId = $item->series_id !== null ? (string) $item->series_id : (string) $item->getKey();
            $targetDate = self::effectiveDate($item);
            $mutated = collect();

            $rows = CalendarItem::query()
                ->where('series_id', $seriesId)
                ->orderBy('occurrence_date')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $isTarget = (string) $row->getKey() === (string) $item->getKey();
                if (! $isTarget) {
                    if ((bool) $row->getAttribute('recurrence_detached')) {
                        continue;
                    }
                    $rowDate = self::effectiveDate($row);
                    if ($targetDate !== null && $rowDate !== null && $rowDate < $targetDate) {
                        continue; // past occurrence — never mutated
                    }
                    if (! in_array($row->status, self::MUTABLE_STATUSES, true)) {
                        continue; // completed / canceled / superseded
                    }
                }
                $mutated->push(self::applyToRow($row, $fields, $actor, $scope, detach: false));
            }

            if ($scope === 'series' && array_key_exists('recurrence_rule', $fields)) {
                $anchor = CalendarItem::query()->whereKey($seriesId)->first();
                if ($anchor !== null && ! $mutated->contains(fn (CalendarItem $r) => (string) $r->getKey() === $seriesId)) {
                    // Rule lives on the anchor even when the anchor is an
                    // earlier occurrence; only the rule column changes.
                    $anchor->recurrence_rule = $fields['recurrence_rule'];
                    $anchor->version = (int) $anchor->version + 1;
                    $anchor->updated_by = (string) $actor->getKey();
                    $anchor->save();
                    AuditLogger::log('calendar.item.updated', $actor, [
                        'item_id' => (string) $anchor->getKey(),
                        'scope' => $scope,
                        'before' => ['recurrence_rule' => $item->recurrence_rule],
                        'after' => ['recurrence_rule' => $fields['recurrence_rule']],
                    ], $anchor->matter);
                }
            }

            return $mutated;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function applyToRow(CalendarItem $row, array $fields, User $actor, string $scope, bool $detach): CalendarItem
    {
        $before = ItemService::snapshot($row);
        $row->fill($fields);
        if ($detach) {
            $row->setAttribute('recurrence_detached', true);
        }
        $row->version = (int) $row->version + 1;
        $row->updated_by = (string) $actor->getKey();
        $row->save();

        AuditLogger::log('calendar.item.updated', $actor, [
            'item_id' => (string) $row->getKey(),
            'scope' => $scope,
            'before' => $before,
            'after' => ItemService::snapshot($row),
        ], $row->matter);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function seriesStart(CalendarItem $anchor, array $rule): ?Carbon
    {
        if ($anchor->occurrence_date !== null) {
            return Carbon::parse($anchor->occurrence_date->toDateString())->startOfDay();
        }
        if ($anchor->due_date !== null) {
            return Carbon::parse($anchor->due_date->toDateString())->startOfDay();
        }
        $startsOn = $rule['starts_on'] ?? null;
        if (is_string($startsOn) && $startsOn !== '' && strtotime($startsOn) !== false) {
            return Carbon::parse($startsOn)->startOfDay();
        }

        return $anchor->starts_at !== null ? Carbon::parse($anchor->starts_at)->startOfDay() : null;
    }

    /**
     * Occurrence dates including the start date. Window: the rule's
     * count or until, otherwise 12 months ahead — whichever ends first.
     * Weekly steps preserve the anchor weekday (byday is stored on the
     * rule; v1 materialization steps by interval from the anchor, as
     * Batch 1's loader did).
     *
     * @param  array<string, mixed>  $rule
     * @return list<Carbon>
     */
    private static function occurrenceDates(Carbon $start, array $rule): array
    {
        $freq = is_string($rule['freq'] ?? null) ? (string) $rule['freq'] : 'weekly';
        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $countRaw = $rule['count'] ?? $rule['occurrences'] ?? null;
        $count = $countRaw !== null ? (int) $countRaw : null;
        $until = null;
        if (isset($rule['until']) && is_string($rule['until']) && strtotime($rule['until']) !== false) {
            $until = Carbon::parse($rule['until'])->startOfDay();
        }
        $windowEnd = $start->copy()->addMonths(12);

        $dates = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 600; $i++) {
            if ($count !== null && count($dates) >= $count) {
                break;
            }
            if ($until !== null && $cursor->greaterThan($until)) {
                break;
            }
            if ($cursor->greaterThan($windowEnd)) {
                break; // 12-month materialization window caps count/until too
            }
            $dates[] = $cursor->copy();
            $cursor = match ($freq) {
                'daily' => $cursor->copy()->addDays($interval),
                'monthly' => $cursor->copy()->addMonths($interval),
                default => $cursor->copy()->addWeeks($interval),
            };
        }

        return $dates;
    }

    private static function effectiveDate(CalendarItem $item): ?string
    {
        if ($item->occurrence_date !== null) {
            return $item->occurrence_date->toDateString();
        }
        if ($item->due_date !== null) {
            return $item->due_date->toDateString();
        }

        return $item->starts_at?->toDateString();
    }
}
