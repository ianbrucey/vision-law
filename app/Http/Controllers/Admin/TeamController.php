<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatterGrant;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin team management (03-contract.md §Routes, admin section).
 * Backend only — no Blade per 001-D06; JSON responses.
 *
 * Membership is computed at request time (no caching of membership): the
 * show endpoint always reads the pivot fresh, so changes take effect
 * immediately (C-09; grant evaluation itself is T-06's AccessControl).
 */
class TeamController extends Controller
{
    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * @return Builder<Team>
     */
    private function orgTeams(Request $request)
    {
        return Team::where('org_id', $this->admin($request)->org_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function teamPayload(Team $team): array
    {
        $team->loadCount('users');
        $team->load(['users' => fn ($query) => $query->orderBy('name')]);

        return [
            'id' => (string) $team->getKey(),
            'name' => $team->name,
            'members_count' => $team->users_count,
            'members' => $team->users->map(fn (User $user) => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            'created_at' => $team->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $teams = $this->orgTeams($request)->withCount('users')->orderBy('name')->get();

        return response()->json([
            'data' => $teams->map(fn (Team $team) => [
                'id' => (string) $team->getKey(),
                'name' => $team->name,
                'members_count' => $team->users_count,
                'created_at' => $team->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        /** @var array{name: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($this->orgTeams($request)->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A team with this name already exists in your organization.'],
            ]);
        }

        $team = DB::transaction(function () use ($admin, $validated): Team {
            $team = Team::create([
                'org_id' => $admin->org_id,
                'name' => $validated['name'],
            ]);

            AuditLogger::log('team.created', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'team_id' => (string) $team->getKey(),
                'name' => $validated['name'],
            ]);

            return $team;
        });

        return response()->json(['data' => $this->teamPayload($team)], 201);
    }

    public function show(Request $request, string $team): JsonResponse
    {
        $model = $this->orgTeams($request)->findOrFail($team);

        return response()->json(['data' => $this->teamPayload($model)]);
    }

    /**
     * Rename and/or replace membership. Membership changes are audited
     * (they feed matter-grant evaluation in T-06).
     */
    public function update(Request $request, string $team): JsonResponse
    {
        $admin = $this->admin($request);

        /** @var array{name?: string, members?: array<string>} $validated */
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'members' => ['sometimes', 'array'],
            'members.*' => ['uuid'],
        ]);

        $model = $this->orgTeams($request)->findOrFail($team);

        if (isset($validated['name'])
            && $this->orgTeams($request)
                ->where('name', $validated['name'])
                ->where('id', '!=', $model->getKey())
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'name' => ['A team with this name already exists in your organization.'],
            ]);
        }

        if (isset($validated['members'])) {
            $memberIds = array_values(array_unique($validated['members']));
            $known = User::where('org_id', $admin->org_id)
                ->whereIn('id', $memberIds)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if (count($known) !== count($memberIds)) {
                throw ValidationException::withMessages([
                    'members' => ['Every member must be a user in your organization.'],
                ]);
            }
        }

        DB::transaction(function () use ($admin, $model, $validated): void {
            if (isset($validated['name']) && $validated['name'] !== $model->name) {
                $model->forceFill(['name' => $validated['name']])->save();

                AuditLogger::log('team.updated', $admin, [
                    'actor_id' => (string) $admin->getKey(),
                    'team_id' => (string) $model->getKey(),
                    'name' => $validated['name'],
                ]);
            }

            if (isset($validated['members'])) {
                $model->users()->sync($validated['members']);

                AuditLogger::log('team.membership.updated', $admin, [
                    'actor_id' => (string) $admin->getKey(),
                    'team_id' => (string) $model->getKey(),
                    'member_ids' => array_values($validated['members']),
                ]);
            }
        });

        return response()->json(['data' => $this->teamPayload($model->fresh())]);
    }

    /**
     * Teams with matter grants cannot be deleted (the grants FK would
     * violate); revoke the grants first (T-06 owns grant revocation).
     */
    public function destroy(Request $request, string $team): Response
    {
        $admin = $this->admin($request);

        $model = $this->orgTeams($request)->findOrFail($team);

        if (MatterGrant::where('team_id', $model->getKey())->exists()) {
            throw ValidationException::withMessages([
                'team' => ['This team has matter grants. Revoke the grants before deleting the team.'],
            ]);
        }

        DB::transaction(function () use ($admin, $model): void {
            $model->users()->detach();
            $model->delete();

            AuditLogger::log('team.deleted', $admin, [
                'actor_id' => (string) $admin->getKey(),
                'team_id' => (string) $model->getKey(),
                'name' => (string) $model->name,
            ]);
        });

        return response()->noContent();
    }
}
