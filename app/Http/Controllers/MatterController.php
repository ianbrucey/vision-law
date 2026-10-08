<?php

namespace App\Http\Controllers;

use App\Exceptions\MatterStateException;
use App\Models\Matter;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\MatterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Matter CRUD + lifecycle (spec 006 T-02; 03-contract.md §Routes).
 *
 * Backend only — JSON responses; Blade screens are T-05. Every mutation
 * goes through MatterService, which writes its audit event in the same
 * transaction. Matter-scoped routes carry RequireMatterAccess (the
 * authorized matter rides on the request attributes); the controller never
 * re-resolves it.
 *
 * Action-level note: the contract pins :edit/:manage, which Ticket 6 adds
 * to AccessControl. Until then the routes below use the EXISTING levels —
 * :update (editor+) for edit/update and :grant (matter_admin+, ≈ manage per
 * the contract) for destroy/transition/close. Ticket 6 renames these
 * middleware parameters when the new levels land.
 */
class MatterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        // MatterPolicy::viewAny — every authenticated user may list; the
        // result set is scoped server-side ("My Matters").
        abort_unless(app(MatterPolicy::class)->viewAny($actor), 403);

        $query = Matter::query()->where('matters.org_id', $actor->org_id);

        if (! $actor->hasRole('org_admin')) {
            // Non-admins see only matters with a valid (unexpired) direct
            // grant or a valid team grant via team membership. The role must
            // be one AccessControl recognizes — unknown role strings are
            // ignored by the authorization layer, so the index must ignore
            // them too (otherwise the list would show matters the actor
            // cannot open).
            $actorId = $actor->getKey();
            $knownRoles = array_keys(AccessControl::ROLE_RANK);

            $query->where(function ($scope) use ($actorId, $knownRoles): void {
                $scope->whereExists(function ($exists) use ($actorId, $knownRoles): void {
                    $exists->selectRaw('1')
                        ->from('matter_grants')
                        ->whereColumn('matter_grants.matter_id', 'matters.id')
                        ->where('matter_grants.user_id', $actorId)
                        ->whereIn('matter_grants.role', $knownRoles)
                        ->where(function ($valid): void {
                            $valid->whereNull('matter_grants.expires_at')
                                ->orWhere('matter_grants.expires_at', '>', now());
                        });
                })->orWhereExists(function ($exists) use ($actorId, $knownRoles): void {
                    $exists->selectRaw('1')
                        ->from('matter_grants')
                        ->join('team_user', 'team_user.team_id', '=', 'matter_grants.team_id')
                        ->whereColumn('matter_grants.matter_id', 'matters.id')
                        ->where('team_user.user_id', $actorId)
                        ->whereIn('matter_grants.role', $knownRoles)
                        ->where(function ($valid): void {
                            $valid->whereNull('matter_grants.expires_at')
                                ->orWhere('matter_grants.expires_at', '>', now());
                        });
                });
            });
        }

        $page = $query->orderByDesc('matters.updated_at')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Matter $matter): array => $this->resource($matter))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorizeCreate($this->actor($request));

        return response()->json([
            'matter_types' => Matter::MATTER_TYPES,
            'lifecycle_states' => Matter::LIFECYCLE_STATES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeCreate($actor);

        /** @var array{title: string, matter_type: string, client_name: string, description?: string|null} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'matter_type' => ['required', 'string', Rule::in(Matter::MATTER_TYPES)],
            'client_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $matter = MatterService::createMatter((string) $actor->org_id, $validated, $actor);

        return response()->json($this->resource($matter), 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->resource($this->authorizedMatter($request)));
    }

    public function edit(Request $request): JsonResponse
    {
        return response()->json($this->resource($this->authorizedMatter($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'matter_type' => ['sometimes', 'required', 'string', Rule::in(Matter::MATTER_TYPES)],
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $updated = MatterService::updateMatter($matter, $validated, $actor);

        return response()->json($this->resource($updated));
    }

    public function destroy(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        MatterService::deleteMatter($matter, $actor);

        return response()->json([
            'id' => (string) $matter->getKey(),
            'deleted_at' => $matter->deleted_at?->toIso8601String(),
        ]);
    }

    /**
     * Restore a soft-deleted matter. RequireMatterAccess cannot resolve
     * trashed rows, so this route carries no matter middleware — the
     * controller enforces org_admin + same-org scoping itself, before any
     * data access.
     */
    public function restore(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $raw = $request->route('matter');

        $matter = is_string($raw) && Str::isUuid($raw)
            ? Matter::withTrashed()->whereKey($raw)->where('org_id', $actor->org_id)->first()
            : null;

        if (! $matter instanceof Matter) {
            // Cross-org / missing / malformed — no existence leak.
            return response()->json(['code' => 'not_found'], 404);
        }

        $restored = MatterService::restoreMatter($matter, $actor);

        return response()->json($this->resource($restored));
    }

    public function transition(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{to: string, note?: string|null} $validated */
        $validated = $request->validate([
            'to' => ['required', 'string', Rule::in(Matter::LIFECYCLE_STATES)],
            'note' => ['nullable', 'string', 'max:20000'],
        ]);

        $transitioned = MatterService::transitionMatter(
            $matter,
            $validated['to'],
            $validated['note'] ?? null,
            $actor
        );

        return response()->json($this->resource($transitioned));
    }

    public function close(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{note?: string|null} $validated */
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:20000'],
        ]);

        $closed = MatterService::closeMatter($matter, $validated['note'] ?? null, $actor);

        return response()->json($this->resource($closed));
    }

    public function summary(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);

        return response()->json(MatterService::statusSummary($matter, $this->actor($request)));
    }

    /**
     * Permission-scoped matter search (spec 006 T-04; MAT-15, C-10).
     *
     * Trigram similarity across title / matter_number / client_name / party
     * names, plus structured filters AND-combined. Permission scoping
     * (Matter::accessibleBy) applies BEFORE pagination — ungranted matters
     * are excluded entirely.
     */
    public function search(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        /** @var array{q?: string|null, state?: string|null, type?: string|null, assignee?: string|null, client?: string|null, updated_since?: string|null} $validated */
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', Rule::in(Matter::LIFECYCLE_STATES)],
            'type' => ['nullable', 'string', Rule::in(Matter::MATTER_TYPES)],
            'assignee' => ['nullable', 'uuid'],
            'client' => ['nullable', 'string', 'max:255'],
            'updated_since' => ['nullable', 'date'],
        ]);

        $page = MatterService::searchMatters($actor, $validated['q'] ?? null, [
            'state' => $validated['state'] ?? null,
            'type' => $validated['type'] ?? null,
            'assignee' => $validated['assignee'] ?? null,
            'client' => $validated['client'] ?? null,
            'updated_since' => $validated['updated_since'] ?? null,
        ]);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Matter $matter): array => $this->resource($matter))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * 006-D03: attorney, org_admin, paralegal only. Denials are audited as
     * matter.access.denied (contract §Error catalog).
     *
     * @throws MatterStateException 403 {code:"forbidden"}
     */
    private function authorizeCreate(User $actor): void
    {
        if (app(MatterPolicy::class)->create($actor)) {
            return;
        }

        AuditLogger::log('matter.access.denied', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'attempted_action' => 'matter.create',
        ]);

        throw new MatterStateException(403, 'forbidden');
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

    /**
     * @return array<string, mixed>
     */
    private function resource(Matter $matter): array
    {
        return [
            'id' => (string) $matter->getKey(),
            'matter_number' => $matter->matter_number,
            'title' => $matter->title,
            'lifecycle_state' => $matter->lifecycle_state,
            'matter_type' => $matter->matter_type,
            'client_name' => $matter->client_name,
            'description' => $matter->description,
            'closed_at' => $matter->closed_at?->toIso8601String(),
            'created_at' => $matter->created_at?->toIso8601String(),
            'updated_at' => $matter->updated_at?->toIso8601String(),
        ];
    }
}
