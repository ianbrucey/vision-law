<?php

namespace App\Http\Controllers\Calendaring;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\CalendarConflictException;
use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\Calendaring\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarItemController extends Controller
{
    public function store(Request $request, string $matter): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $request->attributes->get('matter');
        if (! $model instanceof Matter) {
            abort(404);
        }

        $item = ItemService::create($model, $this->itemInput($request), $actor);

        if ($request->wantsJson()) {
            return response()->json($this->resource($item), 201);
        }

        return redirect()
            ->route('matters.calendar', $model)
            ->with('toast', ['tone' => 'ok', 'message' => 'Calendar item created.']);
    }

    public function show(Request $request, string $item): JsonResponse|View
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'view', $model->matter);

        $model->load(['matter', 'assignee', 'attendees.user', 'supersededBy', 'supersedes']);

        if ($request->wantsJson()) {
            return response()->json($this->resource($model) + [
                'explanation' => ItemService::explain($model),
            ]);
        }

        return view('calendaring.items.show', [
            'item' => $model,
            'matter' => $model->matter,
            'canEdit' => $this->can($actor, 'edit', $model),
            'explanation' => ItemService::explain($model),
        ]);
    }

    public function edit(Request $request, string $item): View
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'edit', $model->matter);

        return view('calendaring.items.edit', [
            'item' => $model,
            'matter' => $model->matter,
        ]);
    }

    public function explain(Request $request, string $item): JsonResponse|View
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'view', $model->matter);

        $explanation = ItemService::explain($model);

        if ($request->wantsJson()) {
            return response()->json($explanation);
        }

        return view('calendaring.items.show', [
            'item' => $model->load(['matter', 'assignee', 'attendees.user']),
            'matter' => $model->matter,
            'canEdit' => $this->can($actor, 'edit', $model),
            'explanation' => $explanation,
        ]);
    }

    public function update(Request $request, string $item): JsonResponse|RedirectResponse|Response
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);

        try {
            $updated = ItemService::update($model, $this->itemInput($request), $actor);
        } catch (CalendarConflictException $e) {
            if ($request->wantsJson()) {
                return response()->json($e->body(), 409);
            }

            return response()->view('calendaring.items.conflict', [
                'item' => $e->current,
                'matter' => $e->current->matter,
            ], 409);
        }

        if ($request->wantsJson()) {
            return response()->json($this->resource($updated));
        }

        return redirect()
            ->route('calendar.items.show', $updated)
            ->with('toast', ['tone' => 'ok', 'message' => 'Calendar item updated.']);
    }

    public function confirm(Request $request, string $item): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $item, 'confirm');
    }

    public function complete(Request $request, string $item): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $item, 'complete');
    }

    public function cancel(Request $request, string $item): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $item, 'cancel');
    }

    public function reopen(Request $request, string $item): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $item, 'reopen');
    }

    private function transition(Request $request, string $item, string $action): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        $updated = ItemService::transition($model, $action, $actor, $request->input('reason'));

        if ($request->wantsJson()) {
            return response()->json($this->resource($updated));
        }

        return redirect()
            ->route('calendar.items.show', $updated)
            ->with('toast', ['tone' => 'ok', 'message' => 'Calendar item '.str_replace('confirm', 'confirmed', $action).(str_ends_with($action, 'e') ? 'd.' : 'ed.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemInput(Request $request): array
    {
        $data = $request->all();

        // HTML datetime-local inputs need a timezone interpretation; the
        // service stores what it is given — forms send ISO values already.
        if (isset($data['all_day'])) {
            $data['all_day'] = filter_var($data['all_day'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['attendees']) && is_string($data['attendees'])) {
            $data['attendees'] = array_values(array_filter(array_map('trim', explode(',', $data['attendees']))));
        }

        return $data;
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

    private function can(User $actor, string $action, CalendarItem $item): bool
    {
        try {
            AccessControl::authorize($actor, $action, $item->matter);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(CalendarItem $item): array
    {
        return [
            'id' => (string) $item->getKey(),
            'matter_id' => (string) $item->matter_id,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'task_status' => $item->task_status,
            'classification' => $item->classification,
            'jurisdiction' => $item->jurisdiction,
            'due_date' => $item->due_date?->toDateString(),
            'cutoff_time' => $item->cutoff_time !== null ? substr((string) $item->cutoff_time, 0, 8) : null,
            'starts_at' => $item->starts_at?->toIso8601String(),
            'ends_at' => $item->ends_at?->toIso8601String(),
            'all_day' => (bool) $item->all_day,
            'timezone' => $item->timezone,
            'location' => $item->location,
            'meeting_url' => $item->meeting_url,
            'assignee_id' => $item->assignee_id,
            'version' => $item->version,
            'source' => $item->source,
            'supersedes_id' => $item->supersedes_id,
            'superseded_by_id' => $item->superseded_by_id,
        ];
    }
}
