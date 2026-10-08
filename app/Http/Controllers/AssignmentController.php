<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterGrant;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Matter assignment endpoints (spec 006 T-04; 03-contract.md §Routes,
 * MAT-07; 006-D05: assignment rides on matter_grants, no new table).
 *
 * - POST   /matters/{matter}/assignments        → store   (matter.access:grant at the
 *          middleware; the SERVICE additionally requires owner/org_admin —
 *          006-D05 + contract "owner or org_admin only")
 * - DELETE /matters/{matter}/assignments/{grant} → destroy (revokes by setting
 *          expires_at — rows are never deleted; last-owner guard → 422)
 *
 * Contract roles: owner, editor, viewer, outside_counsel (03-contract.md
 * §Requests & validation) — honored end-to-end via 006-D11.
 */
class AssignmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{user_id: string, role: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')->where('org_id', $matter->org_id),
            ],
            'role' => ['required', 'string', Rule::in(MatterService::ASSIGNABLE_ROLES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $grant = MatterService::assignUser(
            $matter,
            $validated['user_id'],
            $validated['role'],
            $validated['expires_at'] ?? null,
            $actor
        );

        return response()->json($this->resource($grant->load('user')), 201);
    }

    public function destroy(Request $request, string $matter, string $grant): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $grantModel = $this->resolveGrant($matterModel, $grant);

        MatterService::unassignUser($matterModel, $grantModel, $actor);

        return response()->json([
            'id' => (string) $grantModel->getKey(),
            'expires_at' => $grantModel->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Resolve the {grant} segment scoped to the authorized matter. Unknown
     * ids render 404 (no existence leak).
     *
     * @throws ModelNotFoundException
     */
    private function resolveGrant(Matter $matter, string $raw): MatterGrant
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        return MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->firstOrFail();
    }

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

    /**
     * @return array<string, mixed>
     */
    private function resource(MatterGrant $grant): array
    {
        return [
            'id' => (string) $grant->getKey(),
            'user' => [
                'id' => (string) $grant->user_id,
                'name' => $grant->user?->name,
                'email' => $grant->user?->email,
            ],
            'role' => $grant->role,
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'granted_by' => (string) $grant->granted_by,
        ];
    }
}
