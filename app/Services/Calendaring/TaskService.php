<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\TaskDependency;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    /**
     * @throws ValidationException
     */
    public static function assign(CalendarItem $item, ?string $userId, User $actor): CalendarItem
    {
        AccessControl::authorize($actor, 'edit', $item->matter);
        self::assertTask($item);

        if ($userId === '') {
            $userId = null;
        }

        $newAssignee = null;
        if ($userId !== null) {
            $newAssignee = User::query()->whereKey($userId)->first();
            if ($newAssignee === null || (string) $newAssignee->org_id !== (string) $item->org_id) {
                throw ValidationException::withMessages(['assignee_id' => 'The assignee must be a user in the same organization.']);
            }
        }

        return DB::transaction(function () use ($item, $userId, $actor, $newAssignee): CalendarItem {
            $oldId = $item->assignee_id !== null ? (string) $item->assignee_id : null;
            $oldAssignee = $oldId !== null ? User::query()->whereKey($oldId)->first() : null;

            $item->assignee_id = $userId;
            $item->version = (int) $item->version + 1;
            $item->updated_by = (string) $actor->getKey();
            $item->save();

            AuditLogger::log('calendar.task.assigned', $actor, [
                'item_id' => (string) $item->getKey(),
                'before' => ['assignee_id' => $oldId],
                'after' => ['assignee_id' => $userId],
            ], $item->matter);

            $notification = new TaskAssignedNotification($item->fresh() ?? $item, $oldId, $userId);
            if ($oldAssignee !== null) {
                $oldAssignee->notify($notification);
            }
            if ($newAssignee !== null && (string) $newAssignee->getKey() !== $oldId) {
                $newAssignee->notify($notification);
            }

            return $item;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function transitionStatus(CalendarItem $item, string $taskStatus, User $actor, ?string $overrideReason = null): CalendarItem
    {
        AccessControl::authorize($actor, 'edit', $item->matter);
        self::assertTask($item);

        if (! in_array($taskStatus, CalendarItem::TASK_STATUSES, true)) {
            throw ValidationException::withMessages(['task_status' => 'Unknown task status.']);
        }

        if ($taskStatus === 'completed') {
            return self::complete($item, $actor, $overrideReason);
        }

        $isReopen = $item->task_status === 'completed' && $taskStatus === 'in_progress';

        return DB::transaction(function () use ($item, $taskStatus, $actor, $isReopen): CalendarItem {
            $before = ItemService::snapshot($item);
            $item->task_status = $taskStatus;
            if ($taskStatus === 'canceled') {
                $item->status = 'canceled';
            }
            if ($isReopen) {
                $item->completed_at = null;
                $item->completed_by = null;
                if ($item->status === 'completed') {
                    $item->status = 'confirmed';
                }
            }
            $item->version = (int) $item->version + 1;
            $item->updated_by = (string) $actor->getKey();
            $item->save();

            AuditLogger::log($isReopen ? 'calendar.task.reopened' : 'calendar.item.updated', $actor, [
                'item_id' => (string) $item->getKey(),
                'before' => $before,
                'after' => ItemService::snapshot($item),
            ], $item->matter);

            return $item;
        });
    }

    /**
     * Complete a task through the required-checklist gate (009-D13).
     *
     * @throws ValidationException
     */
    public static function complete(CalendarItem $item, User $actor, ?string $overrideReason = null): CalendarItem
    {
        AccessControl::authorize($actor, 'edit', $item->matter);
        self::assertTask($item);

        $openRequired = $item->checklistItems()->where('required', true)->where('done', false)->count();
        $hasOverride = $overrideReason !== null && trim($overrideReason) !== '';

        if ($openRequired > 0 && ! $hasOverride) {
            throw ValidationException::withMessages([
                'checklist' => 'This task has open required checklist items. Provide an override_reason to complete it anyway.',
            ]);
        }

        return DB::transaction(function () use ($item, $actor, $overrideReason, $openRequired): CalendarItem {
            $before = ItemService::snapshot($item);
            $item->task_status = 'completed';
            $item->status = 'completed';
            $item->completed_at = now();
            $item->completed_by = (string) $actor->getKey();
            $item->version = (int) $item->version + 1;
            $item->updated_by = (string) $actor->getKey();
            $item->save();

            // Past the gate above, open required items imply an override was supplied.
            if ($openRequired > 0) {
                AuditLogger::log('calendar.task.checklist_overridden', $actor, [
                    'item_id' => (string) $item->getKey(),
                    'reason' => (string) $overrideReason,
                    'open_required' => $openRequired,
                    'before' => $before,
                    'after' => ItemService::snapshot($item),
                ], $item->matter);
            }

            AuditLogger::log('calendar.task.completed', $actor, [
                'item_id' => (string) $item->getKey(),
                'before' => $before,
                'after' => ItemService::snapshot($item),
            ], $item->matter);

            return $item;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function addDependency(CalendarItem $predecessor, CalendarItem $successor, User $actor): TaskDependency
    {
        AccessControl::authorize($actor, 'edit', $successor->matter);
        self::assertTask($predecessor);
        self::assertTask($successor);

        if ((string) $predecessor->getKey() === (string) $successor->getKey()) {
            throw ValidationException::withMessages(['predecessor_id' => 'A task cannot depend on itself.']);
        }
        if ((string) $predecessor->matter_id !== (string) $successor->matter_id) {
            throw ValidationException::withMessages(['predecessor_id' => 'Dependencies must be within the same matter.']);
        }
        if (TaskDependency::query()
            ->where('predecessor_item_id', $predecessor->getKey())
            ->where('successor_item_id', $successor->getKey())->exists()) {
            throw ValidationException::withMessages(['predecessor_id' => 'This dependency already exists.']);
        }

        // Cycle check: DFS from the successor following dependents
        // (outgoing finish-to-start edges). If the predecessor is
        // reachable, the new edge would close a cycle.
        if (self::reachable($successor, (string) $predecessor->getKey())) {
            throw ValidationException::withMessages(['predecessor_id' => 'This dependency would create a cycle.']);
        }

        return DB::transaction(function () use ($predecessor, $successor, $actor): TaskDependency {
            $edge = TaskDependency::create([
                'org_id' => (string) $successor->org_id,
                'matter_id' => (string) $successor->matter_id,
                'predecessor_item_id' => (string) $predecessor->getKey(),
                'successor_item_id' => (string) $successor->getKey(),
            ]);

            AuditLogger::log('calendar.task.dependency_changed', $actor, [
                'item_id' => (string) $successor->getKey(),
                'predecessor_id' => (string) $predecessor->getKey(),
                'successor_id' => (string) $successor->getKey(),
                'before' => [],
                'after' => ['predecessor_id' => (string) $predecessor->getKey(), 'successor_id' => (string) $successor->getKey()],
            ], $successor->matter);

            return $edge;
        });
    }

    public static function removeDependency(CalendarItem $predecessor, CalendarItem $successor, User $actor): void
    {
        AccessControl::authorize($actor, 'edit', $successor->matter);

        DB::transaction(function () use ($predecessor, $successor, $actor): void {
            TaskDependency::query()
                ->where('predecessor_item_id', $predecessor->getKey())
                ->where('successor_item_id', $successor->getKey())
                ->delete();

            AuditLogger::log('calendar.task.dependency_changed', $actor, [
                'item_id' => (string) $successor->getKey(),
                'predecessor_id' => (string) $predecessor->getKey(),
                'successor_id' => (string) $successor->getKey(),
                'before' => ['predecessor_id' => (string) $predecessor->getKey(), 'successor_id' => (string) $successor->getKey()],
                'after' => [],
            ], $successor->matter);
        });
    }

    /**
     * Downstream impact of moving a predecessor to $newDueDate.
     * Zero lag: a successor whose due date falls before its
     * predecessor's (possibly shifted) due date shifts by exactly the
     * shortfall, so its proposed due equals that predecessor due date.
     *
     * @return list<array{item_id: string, title: string, current_due: string|null, current_due_date: string|null, proposed_due: string, proposed_due_date: string}>
     */
    public static function previewImpact(CalendarItem $predecessor, mixed $newDueDate): array
    {
        $newDate = Carbon::parse((string) $newDueDate)->startOfDay();

        /** @var array<string, string> $proposedById id => proposed due (Y-m-d) */
        $proposedById = [(string) $predecessor->getKey() => $newDate->toDateString()];
        /** @var array<string, array{item_id: string, title: string, current_due: string|null, current_due_date: string|null, proposed_due: string, proposed_due_date: string}> $impact */
        $impact = [];

        // Breadth-first walk over dependents, in a stable order, so a
        // shifted successor can itself shift its own dependents.
        $queue = [$predecessor];
        $visited = [(string) $predecessor->getKey() => true];
        while ($queue !== []) {
            $current = array_shift($queue);
            $currentProposed = $proposedById[(string) $current->getKey()] ?? $current->due_date?->toDateString();
            if ($currentProposed === null) {
                continue;
            }
            $edges = TaskDependency::query()
                ->where('predecessor_item_id', $current->getKey())
                ->orderBy('successor_item_id')
                ->get();
            foreach ($edges as $edge) {
                $successor = CalendarItem::query()->whereKey($edge->successor_item_id)->first();
                if ($successor === null) {
                    continue;
                }
                $sid = (string) $successor->getKey();
                if ($successor->due_date !== null && $successor->due_date->toDateString() < $currentProposed) {
                    $existing = $proposedById[$sid] ?? null;
                    if ($existing === null || $currentProposed > $existing) {
                        $proposedById[$sid] = $currentProposed;
                        $impact[$sid] = [
                            'item_id' => $sid,
                            'title' => $successor->title,
                            'current_due' => $successor->due_date->toDateString(),
                            'current_due_date' => $successor->due_date->toDateString(),
                            'proposed_due' => $currentProposed,
                            'proposed_due_date' => $currentProposed,
                        ];
                    }
                }
                if (! isset($visited[$sid])) {
                    $visited[$sid] = true;
                    $queue[] = $successor;
                }
            }
        }

        return array_values($impact);
    }

    /**
     * Apply a predecessor move plus the previewed successor shifts.
     *
     * @return array{predecessor: CalendarItem, impact: list<array{item_id: string, title: string, current_due: string|null, current_due_date: string|null, proposed_due: string, proposed_due_date: string}>}
     *
     * @throws ValidationException
     */
    public static function applyMove(CalendarItem $predecessor, mixed $newDueDate, User $actor): array
    {
        AccessControl::authorize($actor, 'edit', $predecessor->matter);
        $impact = self::previewImpact($predecessor, $newDueDate);
        $newDate = Carbon::parse((string) $newDueDate)->toDateString();

        return DB::transaction(function () use ($predecessor, $newDate, $actor, $impact): array {
            $fresh = CalendarItem::query()->whereKey($predecessor->getKey())->firstOrFail();
            ItemService::update($fresh, [
                'due_date' => $newDate,
                'version' => (int) $fresh->version,
            ], $actor);

            foreach ($impact as $row) {
                $successor = CalendarItem::query()->whereKey($row['item_id'])->first();
                if ($successor === null) {
                    continue;
                }
                ItemService::update($successor, [
                    'due_date' => $row['proposed_due_date'],
                    'version' => (int) $successor->version,
                ], $actor);
            }

            return ['predecessor' => $fresh->fresh() ?? $fresh, 'impact' => $impact];
        });
    }

    public static function toggleChecklistItem(ChecklistItem $entry, bool $done, User $actor): ChecklistItem
    {
        $item = $entry->item;
        AccessControl::authorize($actor, 'edit', $item->matter);

        $entry->done = $done;
        $entry->done_by = $done ? (string) $actor->getKey() : null;
        $entry->done_at = $done ? now() : null;
        $entry->save();

        return $entry;
    }

    /**
     * DFS from $start following dependents; true when $targetId is
     * reachable (including when $start itself is the target).
     */
    private static function reachable(CalendarItem $start, string $targetId): bool
    {
        $stack = [(string) $start->getKey()];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === $targetId) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $next = TaskDependency::query()->where('predecessor_item_id', $id)->pluck('successor_item_id');
            foreach ($next as $nextId) {
                $stack[] = (string) $nextId;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    private static function assertTask(CalendarItem $item): void
    {
        if ($item->type !== 'task') {
            throw ValidationException::withMessages(['item' => 'This operation applies to tasks only.']);
        }
    }
}
