<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function matterCalendar(Request $request): View
    {
        $actor = $this->actor($request);
        $matter = $request->attributes->get('matter');
        if (! $matter instanceof Matter) {
            abort(404);
        }

        $items = CalendarItem::query()
            ->forMatter($matter)
            ->with(['assignee'])
            ->agendaOrdered()
            ->get();

        $canEdit = false;
        try {
            AccessControl::authorize($actor, 'edit', $matter);
            $canEdit = true;
        } catch (\Throwable) {
            $canEdit = false;
        }

        return view('calendaring.calendar.matter', [
            'matter' => $matter,
            'items' => $items,
            'canEdit' => $canEdit,
        ]);
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
