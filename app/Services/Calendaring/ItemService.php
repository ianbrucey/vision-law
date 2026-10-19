<?php

namespace App\Services\Calendaring;

use App\Exceptions\CalendarConflictException;
use App\Models\CalendarItem;
use App\Models\ItemAttendee;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemService
{
    /** @var list<string> */
    private const FIELDS = [
        'type', 'title', 'description', 'visibility', 'status', 'task_status',
        'classification', 'jurisdiction', 'due_date', 'cutoff_time', 'starts_at', 'ends_at',
        'all_day', 'timezone', 'location', 'meeting_url', 'assignee_id',
        'escalation_owner_id', 'reviewer_id', 'source', 'template_version_id',
        'instantiation_batch_id', 'date_overridden',
        'rule_key', 'inputs', 'calc_trace', 'series_id', 'occurrence_date', 'recurrence_rule',
    ];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function create(Matter $matter, array $data, User $actor): CalendarItem
    {
        AccessControl::authorize($actor, 'edit', $matter);

        $attrs = self::validateItem($data, null);
        self::assertRange($attrs, null);

        return DB::transaction(function () use ($matter, $data, $attrs, $actor): CalendarItem {
            $item = CalendarItem::create($attrs + [
                'org_id' => (string) $matter->org_id,
                'matter_id' => (string) $matter->getKey(),
                'version' => 1,
                'source' => isset($attrs['source']) && is_string($attrs['source']) ? $attrs['source'] : 'manual',
                'created_by' => (string) $actor->getKey(),
                'updated_by' => (string) $actor->getKey(),
                'calc_trace' => $attrs['calc_trace'] ?? [
                    'source' => 'manual',
                    'steps' => [['op' => 'manual_entry', 'description' => 'Manual entry by '.$actor->name]],
                ],
            ]);

            self::syncAttendees($item, $data);

            AuditLogger::log('calendar.item.created', $actor, [
                'item_id' => (string) $item->getKey(),
                'before' => [],
                'after' => self::snapshot($item),
            ], $matter);

            if ($item->status === 'confirmed') {
                ReminderPlanner::materialize($item);
            }

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CalendarConflictException|ValidationException
     */
    public static function update(CalendarItem $item, array $data, User $actor): CalendarItem
    {
        $matter = $item->matter;
        AccessControl::authorize($actor, 'edit', $matter);

        if (! array_key_exists('version', $data) || $data['version'] === null || $data['version'] === '') {
            throw ValidationException::withMessages(['version' => 'A version is required to save this item.']);
        }

        if ((int) $data['version'] !== (int) $item->version) {
            throw new CalendarConflictException($item->fresh() ?? $item);
        }

        $before = self::snapshot($item);
        $wasConfirmed = $item->status === 'confirmed';

        // Hard-deadline guard (009-D12).
        $isHardConfirmed = $item->status === 'confirmed' && $item->classification === 'hard';
        if ($isHardConfirmed) {
            $dateChanged = array_key_exists('due_date', $data)
                && self::normalizeDate($data['due_date']) !== $item->due_date?->toDateString();
            $classChanged = array_key_exists('classification', $data)
                && $data['classification'] !== $item->classification;
            if (($dateChanged || $classChanged) && trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Changing a confirmed hard deadline’s date or classification requires a reason.',
                ]);
            }
        }

        $attrs = self::validateItem($data, $item);
        self::assertRange($attrs, $item);

        return DB::transaction(function () use ($item, $data, $attrs, $actor, $before, $wasConfirmed): CalendarItem {
            // Re-check version under the transaction (no silent overwrite).
            $fresh = CalendarItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $data['version'] !== (int) $fresh->version) {
                throw new CalendarConflictException($fresh);
            }

            $fresh->fill($attrs);

            // Manual-override marking (009 T-13, 009-D21): a user
            // edit that moves the date of a template-generated item
            // marks it overridden, so recalculation preserves it
            // unless overrides are explicitly reset.
            $filledSnapshot = self::snapshot($fresh);
            if ($fresh->source === 'template'
                && (($before['due_date'] ?? null) !== ($filledSnapshot['due_date'] ?? null)
                    || ($before['starts_at'] ?? null) !== ($filledSnapshot['starts_at'] ?? null))) {
                $fresh->date_overridden = true;
            }

            $fresh->version = (int) $fresh->version + 1;
            $fresh->updated_by = (string) $actor->getKey();
            $fresh->save();

            self::syncAttendees($fresh, $data);

            $payload = [
                'item_id' => (string) $fresh->getKey(),
                'before' => $before,
                'after' => self::snapshot($fresh),
            ];
            if (trim((string) ($data['reason'] ?? '')) !== '') {
                $payload['reason'] = (string) $data['reason'];
            }

            AuditLogger::log('calendar.item.updated', $actor, $payload, $fresh->matter);

            // Reminder hooks (T-06): thin calls into the planner.
            $after = self::snapshot($fresh);
            if (in_array($fresh->status, ['canceled', 'completed'], true)) {
                ReminderPlanner::supersedePending($fresh);
            } elseif ($fresh->status === 'confirmed') {
                $dateChanged = ($before['due_date'] ?? null) !== ($after['due_date'] ?? null)
                    || ($before['starts_at'] ?? null) !== ($after['starts_at'] ?? null)
                    || ($before['cutoff_time'] ?? null) !== ($after['cutoff_time'] ?? null);
                $becameConfirmed = ($before['status'] ?? null) !== 'confirmed';
                $assigneeChanged = ($before['assignee_id'] ?? null) !== ($after['assignee_id'] ?? null);
                if ($becameConfirmed) {
                    ReminderPlanner::materialize($fresh);
                } elseif ($dateChanged || $assigneeChanged) {
                    ReminderPlanner::reschedule($fresh);
                }

                // Material date change on a confirmed item (T-08,
                // 009-D17): an immediate mandatory notice carrying
                // before/after + the actor, via the ledger.
                $materialDateChanged = ($before['due_date'] ?? null) !== ($after['due_date'] ?? null)
                    || ($before['starts_at'] ?? null) !== ($after['starts_at'] ?? null)
                    || ($before['ends_at'] ?? null) !== ($after['ends_at'] ?? null);
                if (! $becameConfirmed && $wasConfirmed && $materialDateChanged) {
                    NoticeService::notifyMany($fresh, NoticeService::recipientsForItem($fresh), 'material_date_change', [
                        'before' => $before,
                        'after' => $after,
                    ], $actor);
                }
            }

            return $fresh;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function transition(CalendarItem $item, string $action, User $actor, ?string $reason = null): CalendarItem
    {
        $matter = $item->matter;
        AccessControl::authorize($actor, 'edit', $matter);

        $map = [
            'confirm' => ['from' => ['draft', 'at_risk'], 'to' => 'confirmed', 'event' => 'calendar.item.confirmed'],
            'complete' => ['from' => ['draft', 'confirmed', 'at_risk'], 'to' => 'completed', 'event' => 'calendar.item.completed'],
            'cancel' => ['from' => ['draft', 'confirmed', 'at_risk'], 'to' => 'canceled', 'event' => 'calendar.item.canceled'],
            'reopen' => ['from' => ['completed', 'canceled'], 'to' => 'draft', 'event' => 'calendar.item.reopened'],
        ];

        if (! isset($map[$action])) {
            throw new \InvalidArgumentException("Unknown transition: {$action}");
        }

        if ($action === 'cancel' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Canceling an item requires a reason.']);
        }

        $rule = $map[$action];
        if (! in_array($item->status, $rule['from'], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot {$action} an item in status {$item->status}.",
            ]);
        }

        return DB::transaction(function () use ($item, $rule, $actor, $reason, $action): CalendarItem {
            $before = self::snapshot($item);
            $item->status = $rule['to'];
            if ($item->type === 'task') {
                $item->task_status = match ($action) {
                    'complete' => 'completed',
                    'cancel' => 'canceled',
                    'reopen' => 'not_started',
                    default => $item->task_status,
                };
            }
            $item->version = (int) $item->version + 1;
            $item->updated_by = (string) $actor->getKey();
            $item->save();

            $payload = [
                'item_id' => (string) $item->getKey(),
                'before' => $before,
                'after' => self::snapshot($item),
            ];
            if (trim((string) $reason) !== '') {
                $payload['reason'] = (string) $reason;
            }

            AuditLogger::log($rule['event'], $actor, $payload, $item->matter);

            if ($rule['to'] === 'confirmed') {
                ReminderPlanner::materialize($item);
                // Immediate notice (T-08): assignee + attendees.
                NoticeService::notifyMany($item, NoticeService::recipientsForItem($item), 'confirmed', [
                    'before' => $before,
                    'after' => self::snapshot($item),
                ], $actor);
            } elseif (in_array($rule['to'], ['canceled', 'completed'], true)) {
                ReminderPlanner::supersedePending($item);
                if ($rule['to'] === 'canceled') {
                    // Immediate notice (T-08): assignee + attendees.
                    NoticeService::notifyMany($item, NoticeService::recipientsForItem($item), 'canceled', [
                        'before' => $before,
                        'after' => self::snapshot($item),
                    ], $actor);
                }
            }

            return $item;
        });
    }

    /**
     * Supersede: successor copies the original's fields (with overrides);
     * the original keeps its dates/provenance byte-identical — only its
     * status, superseded_by link, version and updated_by change.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws ValidationException
     */
    public static function supersede(CalendarItem $item, array $overrides, User $actor, ?string $reason = null): CalendarItem
    {
        $matter = $item->matter;
        AccessControl::authorize($actor, 'edit', $matter);

        if (in_array($item->status, ['superseded', 'canceled'], true)) {
            throw ValidationException::withMessages(['status' => 'A superseded or canceled item cannot be superseded again.']);
        }

        return DB::transaction(function () use ($item, $overrides, $actor, $reason): CalendarItem {
            $copy = $item->only(self::FIELDS);
            // Dates / instants must be scalar for mass assignment.
            $copy['due_date'] = $item->due_date?->toDateString();
            $copy['occurrence_date'] = $item->occurrence_date?->toDateString();
            $copy['starts_at'] = $item->starts_at?->toIso8601String();
            $copy['ends_at'] = $item->ends_at?->toIso8601String();

            $attrs = array_merge($copy, array_intersect_key($overrides, array_flip(self::FIELDS)));
            $validated = self::validateItem($attrs, null);
            self::assertRange($validated, null);

            $successor = CalendarItem::create($validated + [
                'org_id' => (string) $item->org_id,
                'matter_id' => (string) $item->matter_id,
                'version' => 1,
                'supersedes_id' => (string) $item->getKey(),
                'created_by' => (string) $actor->getKey(),
                'updated_by' => (string) $actor->getKey(),
            ]);

            foreach ($item->attendees as $attendee) {
                ItemAttendee::create([
                    'item_id' => (string) $successor->getKey(),
                    'user_id' => $attendee->user_id,
                    'email' => $attendee->email,
                    'name' => $attendee->name,
                ]);
            }

            $before = self::snapshot($item);
            $item->status = 'superseded';
            $item->superseded_by_id = (string) $successor->getKey();
            $item->version = (int) $item->version + 1;
            $item->updated_by = (string) $actor->getKey();
            $item->save();

            $payload = [
                'item_id' => (string) $item->getKey(),
                'successor_id' => (string) $successor->getKey(),
                'before' => $before,
                'after' => self::snapshot($item),
            ];
            if (trim((string) $reason) !== '') {
                $payload['reason'] = (string) $reason;
            }

            AuditLogger::log('calendar.item.superseded', $actor, $payload, $item->matter);

            ReminderPlanner::supersedePending($item);
            if ($successor->status === 'confirmed') {
                ReminderPlanner::materialize($successor);
            }

            return $successor;
        });
    }

    /**
     * Render stored provenance as an explanation array (009-D12).
     *
     * @return array<string, mixed>
     */
    public static function explain(CalendarItem $item): array
    {
        $rawTrace = $item->calc_trace;
        /** @var array<string, mixed> $trace */
        $trace = is_array($rawTrace) ? $rawTrace : [];
        $creator = $item->creator;

        if ($trace !== []) {
            $stepsRaw = array_key_exists('steps', $trace) ? $trace['steps'] : [];
            /** @var list<mixed> $steps */
            $steps = is_array($stepsRaw) ? array_values($stepsRaw) : [$stepsRaw];
            $packVersion = array_key_exists('pack_version', $trace) ? $trace['pack_version'] : null;
            $ruleVersion = array_key_exists('rule_version', $trace) ? $trace['rule_version'] : $packVersion;

            return [
                'source' => (string) (array_key_exists('source', $trace) ? $trace['source'] : $item->source),
                'summary' => (string) (array_key_exists('summary', $trace) ? $trace['summary'] : 'Calculated from stored provenance.'),
                'actor' => $creator !== null ? $creator->name : null,
                'actor_id' => $item->created_by,
                'rule_key' => array_key_exists('rule_key', $trace) ? $trace['rule_key'] : $item->rule_key,
                'pack_version' => $packVersion ?? $ruleVersion,
                'rule_version' => $ruleVersion,
                'template_version_id' => $item->template_version_id,
                'anchor' => array_key_exists('anchor', $trace) ? $trace['anchor'] : null,
                'inputs' => $item->inputs ?? (array_key_exists('inputs', $trace) ? $trace['inputs'] : null),
                'steps' => $steps,
            ];
        }

        $actorName = $creator !== null ? $creator->name : 'unknown user';

        return [
            'source' => 'manual',
            'summary' => 'Manual entry by '.$actorName,
            'actor' => $creator !== null ? $creator->name : null,
            'actor_id' => $item->created_by,
            'rule_key' => $item->rule_key,
            'pack_version' => null,
            'rule_version' => null,
            'template_version_id' => $item->template_version_id,
            'anchor' => null,
            'inputs' => $item->inputs,
            'steps' => [
                ['op' => 'manual_entry', 'description' => 'Manual entry by '.$actorName],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validateItem(array $data, ?CalendarItem $existing): array
    {
        $only = array_intersect_key($data, array_flip(self::FIELDS));

        // When updating, absent fields keep their current values for range checks.
        if ($existing !== null) {
            if (! array_key_exists('due_date', $only)) {
                $only['due_date'] = $existing->due_date?->toDateString();
            }
            if (! array_key_exists('starts_at', $only)) {
                $only['starts_at'] = $existing->starts_at?->toIso8601String();
            }
            if (! array_key_exists('ends_at', $only)) {
                $only['ends_at'] = $existing->ends_at?->toIso8601String();
            }
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($only, [
            'type' => [$existing ? 'sometimes' : 'required', 'string', Rule::in(CalendarItem::TYPES)],
            'title' => [$existing ? 'sometimes' : 'required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'visibility' => ['sometimes', 'string', Rule::in(CalendarItem::VISIBILITIES)],
            'status' => ['sometimes', 'string', Rule::in(CalendarItem::STATUSES)],
            'task_status' => ['nullable', 'string', Rule::in(CalendarItem::TASK_STATUSES)],
            'classification' => ['nullable', 'string', Rule::in(CalendarItem::CLASSIFICATIONS)],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'cutoff_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'all_day' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'string', 'max:2048'],
            'assignee_id' => ['nullable', 'uuid'],
            'escalation_owner_id' => ['nullable', 'uuid'],
            'reviewer_id' => ['nullable', 'uuid'],
            'source' => ['sometimes', 'string', 'max:30'],
            'template_version_id' => ['nullable', 'uuid'],
            'instantiation_batch_id' => ['nullable', 'uuid'],
            'date_overridden' => ['sometimes', 'boolean'],
            'rule_key' => ['nullable', 'string', 'max:255'],
            'inputs' => ['nullable', 'array'],
            'calc_trace' => ['nullable', 'array'],
            'series_id' => ['nullable', 'uuid'],
            'occurrence_date' => ['nullable', 'date'],
            'recurrence_rule' => ['nullable', 'array'],
        ])->validate();

        // cutoff_time rule with two formats fails Validator's handling when
        // value is H:i:s from DB copy; normalize instead of hard failing.
        if (isset($only['cutoff_time']) && is_string($only['cutoff_time'])) {
            $validated['cutoff_time'] = substr($only['cutoff_time'], 0, 8);
        }

        if ($existing !== null) {
            // Range-check-only fields should not be forced back on update
            // unless the caller actually sent them — but keeping them equal
            // is harmless because values are identical.
            foreach (['starts_at', 'ends_at', 'due_date'] as $key) {
                if (! array_key_exists($key, $data)) {
                    unset($validated[$key]);
                }
            }
        }

        // Normalize booleans coming from HTML checkboxes.
        if (array_key_exists('all_day', $validated)) {
            $validated['all_day'] = (bool) $validated['all_day'];
        } elseif (array_key_exists('all_day', $data)) {
            $validated['all_day'] = filter_var($data['all_day'], FILTER_VALIDATE_BOOLEAN);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException
     */
    private static function assertRange(array $attrs, ?CalendarItem $existing): void
    {
        $start = $attrs['starts_at'] ?? ($existing !== null ? $existing->starts_at?->toIso8601String() : null);
        $end = $attrs['ends_at'] ?? ($existing !== null ? $existing->ends_at?->toIso8601String() : null);

        if ($start !== null && $end !== null && strtotime((string) $end) < strtotime((string) $start)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end must not be before the start.',
                'starts_at' => 'The start must not be after the end.',
            ]);
        }
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? (string) $value : date('Y-m-d', $ts);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(CalendarItem $item): array
    {
        return [
            'title' => $item->title,
            'type' => $item->type,
            'status' => $item->status,
            'task_status' => $item->task_status,
            'classification' => $item->classification,
            'jurisdiction' => $item->jurisdiction,
            'due_date' => $item->due_date?->toDateString(),
            'cutoff_time' => $item->cutoff_time !== null ? substr((string) $item->cutoff_time, 0, 8) : null,
            'starts_at' => $item->starts_at?->toIso8601String(),
            'ends_at' => $item->ends_at?->toIso8601String(),
            'assignee_id' => $item->assignee_id,
            'timezone' => $item->timezone,
            'version' => $item->version,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function syncAttendees(CalendarItem $item, array $data): void
    {
        if (! array_key_exists('attendees', $data) || ! is_array($data['attendees'])) {
            return;
        }

        $item->attendees()->delete();

        foreach ($data['attendees'] as $attendee) {
            if (is_string($attendee)) {
                // A bare user id.
                ItemAttendee::create(['item_id' => (string) $item->getKey(), 'user_id' => $attendee]);

                continue;
            }
            if (! is_array($attendee)) {
                continue;
            }
            ItemAttendee::create([
                'item_id' => (string) $item->getKey(),
                'user_id' => $attendee['user_id'] ?? $attendee['user'] ?? null,
                'email' => $attendee['email'] ?? null,
                'name' => $attendee['name'] ?? null,
            ]);
        }
    }
}
