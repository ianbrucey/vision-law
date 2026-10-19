<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarItemController extends Controller
{
    public function show(Request $request, string $item): View
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'view', $model->matter);

        return view('calendaring.items.show', [
            'item' => $model->load(['matter', 'assignee', 'attendees.user']),
            'matter' => $model->matter,
            'canEdit' => $this->can($actor, 'edit', $model),
            'explanation' => [
                'source' => (string) $model->source,
                'summary' => $model->source === 'manual'
                    ? 'Manual entry'
                    : 'Source: '.$model->source,
                'steps' => [],
                'rule_key' => $model->rule_key,
            ],
        ]);
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
        } catch (\Throwable) {
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
}
