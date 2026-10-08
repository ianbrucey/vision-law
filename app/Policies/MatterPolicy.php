<?php

namespace App\Policies;

use App\Exceptions\AccessDeniedException;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;

/**
 * Policy layer for matter actions (03-contract.md §Domain service methods).
 * Every check delegates to AccessControl — the single source of truth — so
 * HTTP middleware and Gate checks can never disagree on a decision.
 *
 * Denials here are NOT audited: the HTTP middleware is the audit point for
 * matter access in this feature (it logs matter.access.denied exactly once
 * per denied request).
 */
class MatterPolicy
{
    /**
     * 006-D03: only attorney, org_admin, and paralegal org roles may create
     * matters. Viewers and outside counsel must not.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('attorney')
            || $user->hasRole('org_admin')
            || $user->hasRole('paralegal');
    }

    /**
     * Every authenticated user may list matters — the result set is scoped
     * server-side ("My Matters": org_admin sees all org matters, everyone
     * else only matters with a valid grant).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Matter $matter): bool
    {
        return $this->allows($user, 'view', $matter);
    }

    public function update(User $user, Matter $matter): bool
    {
        return $this->allows($user, 'update', $matter);
    }

    public function grant(User $user, Matter $matter): bool
    {
        return $this->allows($user, 'grant', $matter);
    }

    public function admin(User $user, Matter $matter): bool
    {
        return $this->allows($user, 'admin', $matter);
    }

    private function allows(User $user, string $action, Matter $matter): bool
    {
        try {
            AccessControl::authorize($user, $action, $matter);
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
