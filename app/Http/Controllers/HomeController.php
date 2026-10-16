<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\User;
use App\Policies\MatterPolicy;
use Illuminate\Http\Request;
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

        return view('home', [
            'user' => $user,
            'orgName' => $user->organization?->name,
            'matters' => $matters,
            'matterCount' => $accessibleIds->count(),
            'activity' => $activity,
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
