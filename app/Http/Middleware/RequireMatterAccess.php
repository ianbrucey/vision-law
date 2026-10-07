<?php

namespace App\Http\Middleware;

use App\Exceptions\AccessDeniedException;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 03-contract.md §Routes (matter section): every /matters/* route carries
 * RequireMatterAccess:<action>.
 *
 * The middleware resolves the matter ITSELF — before any data access — so
 * the authorization decision precedes every read the controller performs.
 * The authorized matter is stashed on the request attributes; controllers
 * must read it from there, never re-resolve it.
 *
 * Leak rules: 404 {code:"not_found"} when the actor has no grant or is
 * cross-org (no existence leak — the title/number never appear); 403
 * {code:"forbidden"} when the matter is visible but the role is insufficient
 * for the action. Every denial is audited as matter.access.denied. The
 * denial is logged against the ACTOR's org: the denial is the actor's
 * tenant's security event, and the rival org's hash chain must not gain rows
 * keyed to a foreign actor's probe.
 */
class RequireMatterAccess
{
    public function handle(Request $request, Closure $next, string $action = 'view'): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // auth middleware runs first; defensive — never leak existence.
            return response()->json(['code' => 'not_found'], 404);
        }

        $matter = $this->resolveMatter($request);

        try {
            AccessControl::authorize($user, $action, $matter);
        } catch (AccessDeniedException $e) {
            AuditLogger::log(
                'matter.access.denied',
                $user,
                [
                    'actor_id' => (string) $user->getKey(),
                    'matter_id' => $matter instanceof Matter
                        ? (string) $matter->getKey()
                        : (string) $request->route('matter'),
                    'attempted_action' => $action,
                ],
                explicitOrgId: (string) $user->org_id
            );

            return response()->json(
                ['code' => $e->httpStatus === 404 ? 'not_found' : 'forbidden'],
                $e->httpStatus
            );
        }

        $request->attributes->set('matter', $matter);

        return $next($request);
    }

    /**
     * Resolve the {matter} route parameter to a model without ever
     * rendering it. Non-UUID input can never match a row (and must not
     * reach Postgres as a uuid bind — invalid syntax would 500).
     */
    private function resolveMatter(Request $request): ?Matter
    {
        $raw = $request->route('matter');

        if ($raw instanceof Matter) {
            return $raw;
        }

        if (! is_string($raw) || ! Str::isUuid($raw)) {
            return null;
        }

        return Matter::query()->whereKey($raw)->first();
    }
}
