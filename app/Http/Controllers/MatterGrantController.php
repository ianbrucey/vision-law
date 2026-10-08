<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Matter grant CRUD (03-contract.md §Routes, matter section; C-11).
 *
 * - create: RequireMatterAccess:grant (matter_admin+). Idempotent: an active
 *   grant for the same (matter, subject) is returned as-is (200); a
 *   revoked/expired row is reactivated (the (matter, subject) pair is
 *   unique, so history lives in the audit log, not in duplicate rows).
 * - revoke: sets expires_at — rows are NEVER deleted (001-D11 history
 *   preservation). A matter must always retain at least one active
 *   owner-level grant; removing the last one is rejected 422 pre-mutation.
 *
 * Audit: matter.grant.created / matter.grant.revoked (allow events).
 * Validation failures (422) write no audit row, per the contract's error
 * catalog ("rejected pre-mutation").
 */
class MatterGrantController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        // The subject must exist IN THE MATTER'S ORG (001-D13: the system org
        // can never own grants; it has no users/teams, so this holds
        // structurally there too).
        $subjectTable = $request->input('subject_type') === 'team' ? 'teams' : 'users';

        /** @var array{subject_type: 'user'|'team', subject_id: string, role: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'team'])],
            'subject_id' => [
                'required',
                'uuid',
                Rule::exists($subjectTable, 'id')->where('org_id', $matter->org_id),
            ],
            'role' => ['required', Rule::in(array_keys(AccessControl::ROLE_RANK))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $subjectColumn = $validated['subject_type'] === 'user' ? 'user_id' : 'team_id';

        return DB::transaction(function () use ($matter, $actor, $validated, $subjectColumn): JsonResponse {
            $existing = MatterGrant::query()
                ->where('matter_id', $matter->getKey())
                ->where($subjectColumn, $validated['subject_id'])
                ->first();

            if ($existing instanceof MatterGrant && ! $this->isExpired($existing)) {
                // Idempotent replay: the active grant already exists.
                return response()->json($this->grantResource($existing), 200);
            }

            if ($existing instanceof MatterGrant) {
                // Reactivate a revoked/expired row: (matter, subject) is
                // unique, so a second row would violate the partial unique
                // index; the revocation history is in the audit log.
                $existing->forceFill([
                    'role' => $validated['role'],
                    'expires_at' => $validated['expires_at'] ?? null,
                    'granted_by' => $actor->getKey(),
                ])->save();

                $grant = $existing;
            } else {
                $grant = MatterGrant::create([
                    'org_id' => $matter->org_id,
                    'matter_id' => $matter->getKey(),
                    $subjectColumn => $validated['subject_id'],
                    'role' => $validated['role'],
                    'expires_at' => $validated['expires_at'] ?? null,
                    'granted_by' => $actor->getKey(),
                ]);
            }

            AuditLogger::log('matter.grant.created', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'subject' => [
                    'type' => $validated['subject_type'],
                    'id' => $validated['subject_id'],
                ],
                'role' => $validated['role'],
                'granted_by' => (string) $actor->getKey(),
            ], $matter);

            return response()->json($this->grantResource($grant), 201);
        });
    }

    /**
     * NOTE: the string route parameters must be declared in ROUTE ORDER
     * (matter, then grant). Laravel's dispatcher splices class type-hints
     * (Request) into the route parameters and invokes the method
     * POSITIONALLY — a ($request, string $grant) signature would receive the
     * matter UUID in $grant. The authorized Matter model always comes from
     * $this->authorizedMatter(), never from the raw $matter segment.
     */
    public function destroy(Request $request, string $matter, string $grant): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        // The grant must belong to the matter in the URL — otherwise 404 via
        // the bootstrap ModelNotFound renderer (no existence leak). The UUID
        // guard keeps garbage input from reaching Postgres as a uuid bind.
        if (! Str::isUuid($grant)) {
            throw new ModelNotFoundException("No grant found for id [{$grant}].");
        }

        $grantRow = MatterGrant::query()
            ->where('matter_id', $matterModel->getKey())
            ->whereKey($grant)
            ->firstOrFail();

        // Guard: a matter must always retain at least one ACTIVE owner-level
        // grant. Rejected pre-mutation → 422, no audit row (contract error
        // catalog: validation-style rejections are not audited).
        if ($this->isActiveOwnerGrant($grantRow, $matterModel)
            && $this->activeOwnerGrantCount($matterModel) <= 1
        ) {
            return response()->json(['code' => 'cannot_remove_last_owner'], 422);
        }

        return DB::transaction(function () use ($grantRow, $matterModel, $actor): JsonResponse {
            $alreadyExpired = $this->isExpired($grantRow);

            // Revoke by expiring — the row is NEVER deleted.
            $grantRow->forceFill(['expires_at' => now()])->save();

            if (! $alreadyExpired) {
                AuditLogger::log('matter.grant.revoked', $actor, [
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $matterModel->getKey(),
                    'subject' => $this->subjectResource($grantRow),
                    'role' => (string) $grantRow->role,
                    'granted_by' => (string) $actor->getKey(),
                ], $matterModel);
            }

            return response()->json([
                'id' => (string) $grantRow->getKey(),
                'expires_at' => $grantRow->expires_at?->toIso8601String(),
            ]);
        });
    }

    /**
     * The matter authorized by RequireMatterAccess (runs before any data
     * access — controllers must never re-resolve it).
     */
    private function authorizedMatter(Request $request): Matter
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        return $matter;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function isExpired(MatterGrant $grant): bool
    {
        return $grant->expires_at !== null && $grant->expires_at->isPast();
    }

    private function isActiveOwnerGrant(MatterGrant $grant, Matter $matter): bool
    {
        // 006-D17: owner-level is a rank comparison (006-D11), not a name
        // match — the contract-named 'owner' grant (rank 5) counts the same
        // as legacy 'matter_owner'.
        return (string) $grant->matter_id === (string) $matter->getKey()
            && AccessControl::roleSatisfies($grant->role, 'matter_owner')
            && ! $this->isExpired($grant);
    }

    private function activeOwnerGrantCount(Matter $matter): int
    {
        // 006-D17: count every active grant whose rank reaches owner level
        // (006-D11) — 'owner' and 'matter_owner' are both rank 5. Derived
        // from ROLE_RANK so a future owner-ranked name counts automatically.
        $ownerRoles = array_keys(array_filter(
            AccessControl::ROLE_RANK,
            static fn (int $rank): bool => $rank >= AccessControl::ROLE_RANK['matter_owner']
        ));

        return MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->whereIn('role', $ownerRoles)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }

    /**
     * @return array{type: string, id: string}
     */
    private function subjectResource(MatterGrant $grant): array
    {
        return $grant->user_id !== null
            ? ['type' => 'user', 'id' => (string) $grant->user_id]
            : ['type' => 'team', 'id' => (string) $grant->team_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function grantResource(MatterGrant $grant): array
    {
        return [
            'id' => (string) $grant->getKey(),
            'matter_id' => (string) $grant->matter_id,
            'subject' => $this->subjectResource($grant),
            'role' => $grant->role,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'granted_by' => (string) $grant->granted_by,
        ];
    }
}
