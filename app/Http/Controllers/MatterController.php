<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\MatterStateException;
use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\MatterDocumentLog;
use App\Models\MatterGrant;
use App\Models\MatterLink;
use App\Models\MatterParty;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\MatterService;
use App\View\Components\Ui\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Matter CRUD + lifecycle (spec 006 T-02; 03-contract.md §Routes).
 *
 * JSON API (T-02/T-04) + Blade screens (T-05; 05-ui.md). Content negotiation
 * is by Accept header: `wantsJson()` → the contract JSON responses (every
 * existing test hits this path); anything else → Blade views. Mutation
 * routes shared by both surfaces redirect back with a session toast on the
 * HTML path (05-ui.md §States: every mutation flashes a toast naming the
 * consequence); validation failures use Laravel's native redirect-back with
 * $errors + old input, which the form partials render inline.
 *
 * Every mutation goes through MatterService, which writes its audit event
 * in the same transaction. Matter-scoped routes carry RequireMatterAccess
 * (the authorized matter rides on the request attributes); the controller
 * never re-resolves it.
 *
 * Action levels: :view < :comment < :edit < :manage (grant ≈ manage).
 */
class MatterController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $actor = $this->actor($request);

        // MatterPolicy::viewAny — every authenticated user may list; the
        // result set is scoped server-side ("My Matters").
        abort_unless(app(MatterPolicy::class)->viewAny($actor), 403);

        if ($request->wantsJson()) {
            return $this->indexJson($actor);
        }

        // HTML (T-05): the matter list screen. Trigram search + AND-combined
        // filters through the permission-scoped search (MAT-15, C-10) —
        // scoping applies BEFORE pagination, so ungranted matters never
        // appear, not even as rows. Filter params are sanitized, never
        // trusted: unknown values are ignored, not 422'd.
        $q = trim((string) $request->query('q', ''));
        $state = $request->query('state');
        $state = is_string($state) && in_array($state, Matter::LIFECYCLE_STATES, true) ? $state : null;
        $type = $request->query('type');
        $type = is_string($type) && in_array($type, Matter::MATTER_TYPES, true) ? $type : null;
        $assignee = $request->query('assignee') === 'me' ? 'me' : null;

        $page = MatterService::searchMatters($actor, $q === '' ? null : $q, [
            'state' => $state,
            'type' => $type,
            'assignee' => $assignee === 'me' ? (string) $actor->getKey() : null,
            'client' => null,
            'updated_since' => null,
        ]);
        $page->appends($request->query());

        return view('matters.index', [
            'matters' => $page,
            'q' => $q,
            'state' => $state,
            'type' => $type,
            'assignee' => $assignee,
            'canCreate' => app(MatterPolicy::class)->create($actor),
            'orgName' => $actor->organization?->name,
            'stateOptions' => $this->lifecycleOptions(),
            'typeOptions' => $this->humanizedOptions(Matter::MATTER_TYPES),
        ]);
    }

    public function create(Request $request): JsonResponse|View
    {
        $this->authorizeCreate($this->actor($request));

        if ($request->wantsJson()) {
            return response()->json([
                'matter_types' => Matter::MATTER_TYPES,
                'lifecycle_states' => Matter::LIFECYCLE_STATES,
            ]);
        }

        return view('matters.create', [
            'typeOptions' => $this->humanizedOptions(Matter::MATTER_TYPES),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
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

        if ($request->wantsJson()) {
            return response()->json($this->resource($matter), 201);
        }

        // HTML: the new-matter form. Validation failures redirect back with
        // $errors + old input natively (05-ui.md §05 error state); success
        // lands on the detail page with a toast naming the consequence.
        return redirect()
            ->route('matters.show', $matter)
            ->with('toast', [
                'tone' => 'ok',
                'message' => "Matter {$matter->matter_number} created.",
            ]);
    }

    public function show(Request $request): JsonResponse|View
    {
        $matter = $this->authorizedMatter($request);

        if ($request->wantsJson()) {
            return response()->json($this->resource($matter));
        }

        $actor = $this->actor($request);
        $tab = $request->query('tab', 'overview');
        $tab = in_array($tab, ['overview', 'documents', 'timeline', 'team'], true) ? $tab : 'overview';

        $summary = MatterService::statusSummary($matter, $actor);
        $effectiveRole = AccessControl::effectiveMatterRole($actor, $matter);

        $view = [
            'matter' => $matter,
            'tab' => $tab,
            'summary' => $summary,
            // Read-only authorization probes for the view: which controls to
            // render. Enforcement stays in the middleware + services.
            'canManage' => $this->may($actor, 'grant', $matter),
            'canEdit' => $matter->lifecycle_state !== 'CLOSED' && $this->may($actor, 'update', $matter),
            // 006-D11/006-D13: the comment floor is outside_counsel —
            // between viewer and editor, so :view/:update alone can't tell.
            'canComment' => AccessControl::roleSatisfies($effectiveRole, 'outside_counsel'),
            'legalNextStates' => MatterService::TRANSITIONS[$matter->lifecycle_state] ?? [],
            'nextStateOptions' => $this->nextStateOptions($matter),
        ];

        $view += match ($tab) {
            'documents' => $this->documentsTab($matter),
            'timeline' => $this->timelineTab($matter, $actor),
            'team' => $this->teamTab($matter, $actor),
            default => $this->overviewTab($matter, $summary),
        };

        return view('matters.show', $view);
    }

    public function edit(Request $request): JsonResponse|View
    {
        $matter = $this->authorizedMatter($request);

        if ($request->wantsJson()) {
            return response()->json($this->resource($matter));
        }

        return view('matters.edit', [
            'matter' => $matter,
            'typeOptions' => $this->humanizedOptions(Matter::MATTER_TYPES),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
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

        try {
            $updated = MatterService::updateMatter($matter, $validated, $actor);
        } catch (MatterStateException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }

            return redirect()->back()->with('toast', [
                'tone' => 'bad',
                'message' => "Update failed ({$e->errorCode}).",
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json($this->resource($updated));
        }

        return redirect()
            ->route('matters.show', $matter)
            ->with('toast', ['tone' => 'ok', 'message' => 'Matter updated.']);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        MatterService::deleteMatter($matter, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $matter->getKey(),
                'deleted_at' => $matter->deleted_at?->toIso8601String(),
            ]);
        }

        // 05-ui.md §States (destructive): the consequence line travels with
        // the toast — soft-deleted matters are recoverable for 30 days.
        return redirect()
            ->route('matters.index')
            ->with('toast', [
                'tone' => 'ok',
                'message' => "Matter {$matter->matter_number} deleted — recoverable by org admins for 30 days.",
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

    public function transition(Request $request): JsonResponse|RedirectResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $rules = [
            'to' => ['required', 'string', Rule::in(Matter::LIFECYCLE_STATES)],
            'note' => ['nullable', 'string', 'max:20000'],
        ];

        // HTML posts from the transition modal get a named error bag so the
        // detail page can surface field errors next to the modal trigger
        // (05-ui.md: transition modal guards); JSON keeps the contract body.
        /** @var array{to: string, note?: string|null} $validated */
        $validated = $request->wantsJson()
            ? $request->validate($rules)
            : Validator::make($request->all(), $rules)->validateWithBag('transition');

        try {
            $transitioned = MatterService::transitionMatter(
                $matter,
                $validated['to'],
                $validated['note'] ?? null,
                $actor
            );
        } catch (MatterStateException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }

            // 05-ui.md §States (conflict): the second writer's modal
            // re-renders with refreshed legal next states; the toast says
            // why the transition didn't happen.
            return redirect()
                ->back()
                ->with('toast', [
                    'tone' => 'bad',
                    'message' => "Transition not allowed ({$e->errorCode}) — the available next states have been refreshed.",
                ]);
        }

        if ($request->wantsJson()) {
            return response()->json($this->resource($transitioned));
        }

        $label = Status::MAP['matter'][$validated['to']]['label'] ?? $validated['to'];

        return redirect()
            ->route('matters.show', $matter)
            ->with('toast', ['tone' => 'ok', 'message' => "Matter moved to {$label}."]);
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
     * Immutable activity feed (spec 006 C-07; 006-D04). A read model over
     * audit_events — no separate table. Newest-first, filterable by event
     * type (?type=) and actor (?actor=), paginated. Authorization is :view:
     * the feed only ever surfaces rows for a matter the actor may already
     * see, so it cannot widen access. Denial probes (matter.access.denied)
     * appear here for the same reason — they are this matter's own audit
     * rows.
     */
    public function feed(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);

        /** @var array{type?: string|null, actor?: string|null, per_page?: int|null} $validated */
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:200'],
            'actor' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditEvent::query()
            ->with('actor')
            ->where('org_id', $matter->org_id)
            ->where('matter_id', $matter->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (($validated['type'] ?? null) !== null) {
            $query->where('event', $validated['type']);
        }

        if (($validated['actor'] ?? null) !== null) {
            $query->where('actor_id', $validated['actor']);
        }

        $page = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (AuditEvent $event): array => $this->feedResource($event))
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
     * @return array<string, mixed>
     */
    private function feedResource(AuditEvent $event): array
    {
        return [
            'id' => (string) $event->getKey(),
            'event' => $event->event,
            'actor' => $event->actor_id !== null ? [
                'id' => (string) $event->actor_id,
                'name' => $event->actor?->name,
            ] : null,
            'created_at' => $event->created_at?->toIso8601String(),
            'payload' => $event->payload,
        ];
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
     * Read-only authorization probe for Blade views: which controls to
     * render. Enforcement stays in the middleware and the services — the
     * view never grants anything.
     */
    private function may(User $actor, string $action, Matter $matter): bool
    {
        try {
            AccessControl::authorize($actor, $action, $matter);
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $summary  MatterService::statusSummary() shape
     * @return array<string, mixed>
     */
    private function overviewTab(Matter $matter, array $summary): array
    {
        $parties = $matter->parties()->orderBy('name')->get();

        $lead = MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->whereIn('role', ['owner', 'matter_owner'])
            ->where(function ($valid): void {
                $valid->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user')
            ->orderBy('created_at')
            ->first();

        // The summary's last_activity carries the actor id only — resolve the
        // display name for the "Last activity" row (same-org user directory
        // the actor is already entitled to see on this matter's team tab).
        $lastActorId = $summary['last_activity']['actor'] ?? null;
        $lastActorName = is_string($lastActorId)
            ? User::query()->whereKey($lastActorId)->value('name')
            : null;

        return [
            'parties' => $parties,
            'leadAttorneyName' => $lead?->user?->name,
            'lastActivityActorName' => $lastActorName,
            'partyTypeOptions' => $this->humanizedOptions(MatterParty::TYPES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentsTab(Matter $matter): array
    {
        $logs = $matter->documentLogs()
            ->orderByDesc('logged_at')
            ->orderByDesc('created_at')
            ->paginate(15);
        $logs->appends(['tab' => 'documents']);

        return [
            'logs' => $logs,
            'directionOptions' => ['received' => 'Received', 'sent' => 'Sent'],
            'methodOptions' => $this->humanizedOptions(MatterDocumentLog::METHODS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineTab(Matter $matter, User $actor): array
    {
        // The activity feed is a read model over the tamper-evident audit
        // trail (C-07 verdict shape lives in feed()); the Blade tab renders
        // the same rows newest-first, paginated at 20 (05-ui.md).
        $activity = AuditEvent::query()
            ->where('matter_id', $matter->getKey())
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);
        $activity->appends(['tab' => 'timeline']);

        $comments = $matter->comments()
            ->whereNull('parent_id')
            ->with([
                'author',
                'replies' => fn ($replies) => $replies->with('author')->orderBy('created_at'),
            ])
            ->orderBy('created_at')
            ->get();

        return [
            'activity' => $activity,
            'comments' => $comments,
            'actorId' => (string) $actor->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamTab(Matter $matter, User $actor): array
    {
        $grants = MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->with(['user', 'team'])
            ->orderBy('created_at')
            ->get();

        $ownerCount = $grants
            ->whereIn('role', ['owner', 'matter_owner'])
            ->filter(fn (MatterGrant $grant): bool => $grant->expires_at === null || $grant->expires_at->isFuture())
            ->count();

        // Related matters — mirrors LinkController@index presentation rules:
        // a related matter the actor cannot access renders as
        // {restricted: true} — no title, no metadata (leak sentinel).
        $matterId = (string) $matter->getKey();

        $links = MatterLink::query()
            ->where(function ($query) use ($matterId): void {
                $query->where('matter_id', $matterId)
                    ->orWhere('related_matter_id', $matterId);
            })
            ->orderBy('created_at')
            ->get();

        $otherIds = $links
            ->map(fn (MatterLink $link): string => (string) $link->matter_id === $matterId
                ? (string) $link->related_matter_id
                : (string) $link->matter_id)
            ->unique()
            ->values();

        $others = Matter::query()
            ->whereKey($otherIds)
            ->get()
            ->keyBy(fn (Matter $m): string => (string) $m->getKey());

        $related = $links->map(function (MatterLink $link) use ($matterId, $actor, $others): array {
            $otherId = (string) $link->matter_id === $matterId
                ? (string) $link->related_matter_id
                : (string) $link->matter_id;

            /** @var Matter|null $other */
            $other = $others->get($otherId);

            $canView = $other instanceof Matter
                && AccessControl::effectiveMatterRole($actor, $other) !== null;

            return [
                'id' => (string) $link->getKey(),
                'link_type' => $link->link_type,
                'note' => $link->note,
                'related_matter' => $canView
                    ? [
                        'id' => $otherId,
                        'matter_number' => $other->matter_number,
                        'title' => $other->title,
                        'lifecycle_state' => $other->lifecycle_state,
                    ]
                    : ['restricted' => true],
            ];
        })->values()->all();

        return [
            'grants' => $grants,
            'ownerCount' => $ownerCount,
            'related' => $related,
            'grantRoleLabels' => [
                'owner' => 'Owner',
                'matter_owner' => 'Owner',
                'matter_admin' => 'Admin',
                'editor' => 'Editor',
                'viewer' => 'Viewer',
                'outside_counsel' => 'Outside counsel',
            ],
            'linkTypeOptions' => $this->humanizedOptions(MatterLink::TYPES),
        ];
    }

    /**
     * The untouched JSON index payload (T-02/T-04 contract).
     */
    private function indexJson(User $actor): JsonResponse
    {
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

    /**
     * @return array<string, string> legal next-state value => label, for the
     *                               transition modal's <x-ui.select>.
     */
    private function nextStateOptions(Matter $matter): array
    {
        $options = [];

        foreach (MatterService::TRANSITIONS[$matter->lifecycle_state] ?? [] as $state) {
            $options[$state] = Status::MAP['matter'][$state]['label'];
        }

        return $options;
    }

    /**
     * value => Humanized label, for <x-ui.select> option lists.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function humanizedOptions(array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = ucfirst(str_replace('_', ' ', $value));
        }

        return $options;
    }

    /**
     * @return array<string, string> lifecycle state value => label
     */
    private function lifecycleOptions(): array
    {
        $options = [];

        foreach (Matter::LIFECYCLE_STATES as $state) {
            $options[$state] = Status::MAP['matter'][$state]['label'];
        }

        return $options;
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
