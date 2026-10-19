<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\Calendaring\AgendaQuery;
use App\Services\Calendaring\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarTaskController extends Controller
{
    public function triage(Request $request): JsonResponse|View
    {
        $actor = $this->actor($request);
        $items = AgendaQuery::scopedQuery($actor, ['types' => ['task']])
            ->whereNull('calendar_items.assignee_id')
            ->where('calendar_items.status', 'confirmed')
            ->where(function ($q): void {
                $q->whereNull('calendar_items.task_status')->orWhere('calendar_items.task_status', '!=', 'canceled');
            })
            ->with(['matter', 'assignee'])
            ->orderByRaw('coalesce(calendar_items.due_date, calendar_items.occurrence_date, calendar_items.starts_at::date) asc nulls last')
            ->orderBy('calendar_items.title')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'items' => $items->map(fn (CalendarItem $item) => [
                    'id' => (string) $item->getKey(),
                    'matter_id' => (string) $item->matter_id,
                    'title' => $item->title,
                    'status' => $item->status,
                    'task_status' => $item->task_status,
                    'due_date' => $item->due_date?->toDateString(),
                    'assignee_id' => $item->assignee_id,
                ])->values(),
            ]);
        }

        return view('calendaring.calendar.triage', ['items' => $items]);
    }

    public function assign(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        $assigneeId = $request->input('assignee_id');
        $updated = TaskService::assign($model, is_string($assigneeId) && $assigneeId !== '' ? $assigneeId : null, $actor);

        return $this->respond($request, $updated, 'Task assignment updated.');
    }

    public function taskStatus(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        $status = (string) ($request->input('task_status') ?? $request->input('status') ?? '');
        $override = $request->input('override_reason');
        $updated = TaskService::transitionStatus($model, $status, $actor, is_string($override) ? $override : null);

        return $this->respond($request, $updated, 'Task status updated.');
    }

    public function storeDependency(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $successor = $this->resolveItem($item);
        $predecessorId = (string) ($request->input('predecessor_id') ?? $request->input('predecessor_item_id') ?? '');
        $predecessor = $this->resolveItem($predecessorId);
        $edge = TaskService::addDependency($predecessor, $successor, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $edge->getKey(),
                'predecessor_id' => (string) $edge->predecessor_item_id,
                'successor_id' => (string) $edge->successor_item_id,
            ], 201);
        }

        return redirect()->route('calendar.items.show', $successor)->with('toast', ['tone' => 'ok', 'message' => 'Dependency added.']);
    }

    public function destroyDependency(Request $request, string $item, string $predecessor): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $successor = $this->resolveItem($item);
        $pred = $this->resolveItem($predecessor);
        TaskService::removeDependency($pred, $successor, $actor);

        if ($request->wantsJson()) {
            return response()->json(['removed' => true]);
        }

        return redirect()->route('calendar.items.show', $successor)->with('toast', ['tone' => 'ok', 'message' => 'Dependency removed.']);
    }

    public function previewMove(Request $request, string $item): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        // Preview is a read over an edit-scoped operation: require edit
        // via the service-level check used by applyMove.
        AccessControl::authorize($actor, 'edit', $model->matter);
        $date = $request->input('new_due_date') ?? $request->input('due_date');
        $impact = TaskService::previewImpact($model, $date);

        return response()->json(['impact' => $impact, 'items' => $impact]);
    }

    public function applyMove(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        $date = $request->input('new_due_date') ?? $request->input('due_date');
        $result = TaskService::applyMove($model, $date, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $result['predecessor']->getKey(),
                'due_date' => $result['predecessor']->due_date?->toDateString(),
                'impact' => $result['impact'],
            ]);
        }

        return redirect()->route('calendar.items.show', $result['predecessor'])->with('toast', ['tone' => 'ok', 'message' => 'Task moved.']);
    }

    public function toggleChecklist(Request $request, string $item, string $entry): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        if (! Str::isUuid($entry)) {
            abort(404);
        }
        $checklistItem = ChecklistItem::query()->whereKey($entry)->where('item_id', $model->getKey())->first();
        if ($checklistItem === null) {
            abort(404);
        }
        $done = $request->has('done')
            ? filter_var($request->input('done'), FILTER_VALIDATE_BOOLEAN)
            : ! $checklistItem->done;
        $updated = TaskService::toggleChecklistItem($checklistItem, (bool) $done, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $updated->getKey(),
                'done' => (bool) $updated->done,
                'done_by' => $updated->done_by,
                'done_at' => $updated->done_at?->toIso8601String(),
            ]);
        }

        return redirect()->route('calendar.items.show', $model)->with('toast', ['tone' => 'ok', 'message' => 'Checklist updated.']);
    }

    private function respond(Request $request, CalendarItem $item, string $message): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $item->getKey(),
                'title' => $item->title,
                'status' => $item->status,
                'task_status' => $item->task_status,
                'assignee_id' => $item->assignee_id,
                'due_date' => $item->due_date?->toDateString(),
                'completed_at' => $item->completed_at?->toIso8601String(),
                'completed_by' => $item->completed_by,
                'version' => $item->version,
            ]);
        }

        return redirect()->route('calendar.items.show', $item)->with('toast', ['tone' => 'ok', 'message' => $message]);
    }

    private function resolveItem(string $id): CalendarItem
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $item = CalendarItem::query()->with('matter')->whereKey($id)->first();
        if ($item === null) {
            abort(404);
        }

        return $item;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
