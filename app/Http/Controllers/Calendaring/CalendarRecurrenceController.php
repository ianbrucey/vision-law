<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\User;
use App\Services\Calendaring\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CalendarRecurrenceController extends Controller
{
    public function update(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);

        $scope = (string) ($request->input('scope') ?? 'this');
        $changes = $request->except(['scope', '_token', '_method']);
        $updated = RecurrenceService::editWithScope($model, $scope, $changes, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'scope' => $scope,
                'updated_ids' => $updated->map(fn (CalendarItem $row) => (string) $row->getKey())->values(),
                'items' => $updated->map(fn (CalendarItem $row) => [
                    'id' => (string) $row->getKey(),
                    'title' => $row->title,
                    'occurrence_date' => $row->occurrence_date?->toDateString(),
                    'series_id' => $row->series_id,
                    'recurrence_detached' => (bool) $row->getAttribute('recurrence_detached'),
                ])->values(),
            ]);
        }

        return redirect()->route('calendar.items.show', $model)
            ->with('toast', ['tone' => 'ok', 'message' => 'Recurring item updated ('.$scope.').']);
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
