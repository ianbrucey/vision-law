<?php

namespace App\Services;

use App\Exceptions\AccessDeniedException;
use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\User;

/**
 * Matter-scoped authorization (C-08–C-11; 03-contract.md §Domain service
 * methods, §Permission matrix).
 *
 * Effective permission is the MOST PERMISSIVE of:
 *   1. the org default from the matrix — org_admin is implicit matter_owner
 *      on all org matters; every other org role is default-deny at matter
 *      scope (access only via explicit grants); outside_counsel ALWAYS
 *      ignores the org default, even if it somehow also held an admin role;
 *   2. team grants, via team membership, computed at request time (no
 *      caching — C-09 membership changes take effect immediately);
 *   3. direct user grants.
 *
 * Expired grants (expires_at in the past) deny automatically. Deactivated
 * users deny everything. Cross-org access is treated as no access (the
 * middleware renders 404 — no existence leak). 001-D13: queries are scoped
 * to the matter's org; the system org can never own matters or grants, and
 * no user can belong to it, so the org-equality check covers it
 * structurally.
 */
class AccessControl
{
    /**
     * Matter role hierarchy, least → most permissive (03-contract.md).
     *
     * 006-D11 reconciles the contract's assignment roles with the legacy
     * T-02 names: 'owner' is the contract name for 'matter_owner' (same
     * rank), and 'outside_counsel' sits between viewer and editor so
     * Ticket 6 can pin :comment at outside_counsel (view+comment, no edit)
     * without changing any T-02 authorization. Only the relative order
     * matters — roleSatisfies() compares by name lookup.
     *
     * @var array<string, int>
     */
    public const ROLE_RANK = [
        'viewer' => 1,
        'outside_counsel' => 2,
        'editor' => 3,
        'matter_admin' => 4,
        'matter_owner' => 5,
        // Contract alias of matter_owner (03-contract.md Requests &
        // validation; 04-fixtures.json already stores this name).
        'owner' => 5,
    ];

    /**
     * Minimum matter role for each authorize() action (03-contract.md
     * §Domain service methods; §Routes pins :grant at matter_admin+).
     *
     * @var array<string, string>
     */
    public const ACTION_MIN_ROLE = [
        'view' => 'viewer',
        'update' => 'editor',
        'grant' => 'matter_admin',
        'admin' => 'matter_owner',
    ];

    /**
     * The most permissive active matter role for the user, or null when the
     * user has no access (deactivated, cross-org, or no grant).
     */
    public static function effectiveMatterRole(User $user, Matter $matter): ?string
    {
        // Deactivated users deny everything.
        if ($user->deactivated_at !== null) {
            return null;
        }

        // Cross-org: no access.
        if ((string) $user->org_id !== (string) $matter->org_id) {
            return null;
        }

        /** @var list<string> $candidates */
        $candidates = [];

        // Org default from the matrix. outside_counsel ignores it unconditionally.
        if (! $user->hasRole('outside_counsel') && $user->hasRole('org_admin')) {
            $candidates[] = 'matter_owner';
        }

        // Direct grants + team grants (membership computed at request time).
        // Expired grants are excluded — they deny automatically.
        $teamIds = $user->teams()->pluck('teams.id');

        $grantRoles = MatterGrant::query()
            ->where('org_id', $matter->org_id)
            ->where('matter_id', $matter->getKey())
            ->where(function ($query) use ($user, $teamIds): void {
                $query->where('user_id', $user->getKey());

                if ($teamIds->isNotEmpty()) {
                    $query->orWhereIn('team_id', $teamIds);
                }
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('role');

        foreach ($grantRoles as $grantRole) {
            $candidates[] = (string) $grantRole;
        }

        // Most permissive wins. Unknown role strings are ignored defensively
        // (the write path validates against ROLE_RANK).
        $best = null;

        foreach ($candidates as $candidate) {
            if (! isset(self::ROLE_RANK[$candidate])) {
                continue;
            }

            if ($best === null || self::ROLE_RANK[$candidate] > self::ROLE_RANK[$best]) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Whether an effective role satisfies a required role.
     */
    public static function roleSatisfies(?string $effective, string $required): bool
    {
        return $effective !== null
            && isset(self::ROLE_RANK[$effective], self::ROLE_RANK[$required])
            && self::ROLE_RANK[$effective] >= self::ROLE_RANK[$required];
    }

    /**
     * Authorize an action on a matter. Returns void on allow.
     *
     * @throws AccessDeniedException 404 when the actor has no grant at all
     *                               (missing matter, cross-org, deactivated actor, or no grant — none of
     *                               these may reveal the matter exists), 403 when the matter is visible
     *                               but the role is insufficient for the action.
     * @throws \InvalidArgumentException on an unknown action (programmer error)
     */
    public static function authorize(User $user, string $action, ?Matter $matter): void
    {
        if (! isset(self::ACTION_MIN_ROLE[$action])) {
            throw new \InvalidArgumentException("Unknown authorization action: {$action}.");
        }

        // 404 — not 403 — for every case where the actor must not learn the
        // matter exists.
        if (! $matter instanceof Matter
            || (string) $user->org_id !== (string) $matter->org_id
            || $user->deactivated_at !== null
        ) {
            throw new AccessDeniedException(404);
        }

        $effective = self::effectiveMatterRole($user, $matter);

        if ($effective === null) {
            throw new AccessDeniedException(404);
        }

        if (! self::roleSatisfies($effective, self::ACTION_MIN_ROLE[$action])) {
            throw new AccessDeniedException(403);
        }
    }
}
