<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * C-07: absolute session lifetime for privileged roles. The login instant is
 * anchored in the session by the Login listener (EnforceSessionPolicies);
 * once now - login_at exceeds the configured lifetime the session is killed
 * and the request is denied. The Logout event fires auth.logout via the
 * existing AuthEventSubscriber, so the expiry is audited without inventing
 * a new event.
 *
 * Which roles count as privileged: the contract names none, so the default
 * is org_admin + attorney (config/visionlaw.php `privileged_roles`).
 * Sessions predating the anchor get it on first sight — never insta-expire.
 */
class EnsureSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->hasSession() && $this->isPrivileged($user)) {
            $loginAt = $request->session()->get('visionlaw.login_at');

            if ($loginAt === null) {
                $request->session()->put('visionlaw.login_at', now()->timestamp);
            } elseif (now()->getTimestamp() - (int) $loginAt > $this->maxLifetimeSeconds()) {
                Auth::guard()->logout();

                return response()->json(['code' => 'session_expired'], 403);
            }
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function privilegedRoles(): array
    {
        /** @var list<string> $roles */
        $roles = config('visionlaw.privileged_roles', ['org_admin', 'attorney']);

        return $roles;
    }

    private function isPrivileged(User $user): bool
    {
        return $user->hasAnyRole($this->privilegedRoles());
    }

    private function maxLifetimeSeconds(): int
    {
        return (int) config('visionlaw.session_absolute_lifetime_hours', 12) * 3600;
    }
}
