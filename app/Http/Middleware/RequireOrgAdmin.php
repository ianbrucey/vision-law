<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 03-contract.md §Routes (admin section): every /admin/* route requires the
 * org_admin role. Insufficient role on a visible object → 403 (not 404):
 * the admin section's existence is not a secret, the role is.
 *
 * Denials are audited as user.admin.denied (03-contract.md §Error catalog).
 */
class RequireOrgAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole('org_admin')) {
            return $next($request);
        }

        if ($user instanceof User) {
            AuditLogger::log('user.admin.denied', $user, [
                'actor_id' => (string) $user->getKey(),
                'path' => '/'.$request->path(),
            ]);
        }

        return response()->json(['code' => 'forbidden'], 403);
    }
}
