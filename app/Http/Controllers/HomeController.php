<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Services\Calendaring\AgendaQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 010 T-02: the authenticated app home (dashboard v1).
 *
 * Read-only assembly over existing data. Matters and activity are scoped
 * by spec 006 authorization (Matter::accessibleBy) — never by home-specific
 * rules (C-03, C-09). No writes, no new audit events; the deadlines module
 * joins this view at spec 009 Plan time (010-D08).
 */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->actor($request);

        $accessibleIds = Matter::query()->accessibleBy($user)->pluck('id');

        $matters = Matter::query()
            ->accessibleBy($user)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $activity = AuditEvent::query()
            ->with(['actor', 'matter'])
            ->whereIn('matter_id', $accessibleIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // Deadlines module (spec 009 T-17, 009-D25 — the reserved
        // 010-D08 slot): computed only when the user can access at
        // least one matter; null hides the module entirely so the
        // zero-access behavior of spec 010 is preserved (C-04).
        $deadlines = null;
        if ($accessibleIds->isNotEmpty()) {
            $today = Carbon::today();
            $viewerTz = AgendaQuery::viewerTimezoneFor($user);
            $dateExpr = 'coalesce(calendar_items.due_date, calendar_items.occurrence_date, (calendar_items.starts_at at time zone ?)::date)';
            $confirmed = AgendaQuery::scopedQuery($user)
                ->whereIn('calendar_items.status', ['confirmed', 'at_risk']);

            $upcoming = (clone $confirmed)
                ->with(['matter', 'assignee'])
                ->orderByRaw($dateExpr.' asc nulls last', [$viewerTz])
                ->orderBy('calendar_items.starts_at')
                ->orderBy('calendar_items.title')
                ->limit(5)
                ->get()
                ->each(function ($item) use ($today, $viewerTz): void {
                    $date = AgendaQuery::effectiveDate($item, $viewerTz);
                    $item->setAttribute('home_date', $date);
                    $item->setAttribute('home_overdue', $date !== null && $date < $today->toDateString());
                });

            $overdue = (clone $confirmed)
                ->whereRaw($dateExpr.' < ?', [$viewerTz, $today->toDateString()])
                ->count();
            $dueWithin7 = (clone $confirmed)
                ->whereRaw($dateExpr.' between ? and ?', [$viewerTz, $today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                ->count();
            $unackedMandatory = ReminderDelivery::query()
                ->where('recipient_user_id', $user->getKey())
                ->whereIn('matter_id', $accessibleIds)
                ->where('kind', 'reminder')
                ->where('status', 'sent')
                ->where('payload->criticality', 'mandatory')
                ->count();

            $deadlines = [
                'upcoming' => $upcoming,
                'overdue' => $overdue,
                'dueWithin7' => $dueWithin7,
                'unackedMandatory' => $unackedMandatory,
            ];
        }

        return view('home', [
            'user' => $user,
            'orgName' => $user->organization?->name,
            'matters' => $matters,
            'matterCount' => $accessibleIds->count(),
            'activity' => $activity,
            'deadlines' => $deadlines,
            'orgHasMatters' => Matter::query()->where('org_id', $user->org_id)->exists(),
            'canCreateMatter' => app(MatterPolicy::class)->create($user),
            'isOrgAdmin' => $user->hasRole('org_admin'),
            'twoFactorConfirmed' => $user->hasEnabledTwoFactorAuthentication(),
            'passkeyCount' => $user->webAuthnCredentials()->whereEnabled()->count(),
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
