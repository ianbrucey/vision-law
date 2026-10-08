<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DispositionQueue;
use App\Models\Document;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\RetentionPolicy;
use App\Models\User;
use App\Services\LegalHoldService;
use App\Services\RetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Retention administration (spec 007 T-09, DOC-27/28/29; 03-contract.md
 * §Retention routes).
 *
 * - Policy CRUD (versioned) + activation + read-only simulator, behind
 *   org_admin (the admin route group). Audited as
 *   retention.policy.created / retention.policy.activated.
 * - Disposition queue: org_admin requests, approves (dual control for
 *   destroy), rejects. Audited as document.disposition.decided (+
 *   document.destroyed on execution; document.destroy.denied on
 *   hold-blocked 423).
 * - Hold management: org-wide active-hold list; release requires the
 *   legal-hold role + a logged reason (403 otherwise).
 *
 * Dual response: web requests (Accept: text/html) receive Blade views /
 * redirects; API clients ($request->wantsJson()) receive JSON.
 */
class RetentionController extends Controller
{
    // ── helpers ───────────────────────────────────────────────

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    private function policyFor(User $admin, string $id): RetentionPolicy
    {
        /** @var RetentionPolicy|null $policy */
        $policy = RetentionPolicy::query()
            ->where('org_id', $admin->org_id)
            ->whereKey($id)
            ->first();

        abort_if($policy === null, 404);

        /** @var RetentionPolicy $policy */
        return $policy;
    }

    private function queueEntryFor(User $admin, string $id): DispositionQueue
    {
        /** @var DispositionQueue|null $entry */
        $entry = DispositionQueue::query()
            ->whereHas('document', fn ($q) => $q->where('org_id', $admin->org_id))
            ->whereKey($id)
            ->first();

        abort_if($entry === null, 404);

        /** @var DispositionQueue $entry */
        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function policyPayload(RetentionPolicy $policy): array
    {
        return [
            'id' => (string) $policy->getKey(),
            'name' => $policy->name,
            'category' => $policy->category,
            'matter_type' => $policy->matter_type,
            'trigger' => $policy->trigger,
            'disposition' => $policy->disposition,
            'legal_basis' => $policy->legal_basis,
            'retention_period' => $policy->periodLabel(),
            'fixed_date' => $policy->fixed_date?->toIso8601String(),

            'version' => $policy->version,
            'status' => $policy->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queuePayload(DispositionQueue $entry): array
    {
        $document = $entry->document;

        return [
            'id' => (string) $entry->getKey(),
            'document_id' => (string) $entry->document_id,
            'document_title' => $document?->title,
            'matter_id' => $document === null ? null : (string) $document->matter_id,
            'action' => $entry->action,
            'status' => $entry->status,
            'approvals' => $entry->approvals ?? [],
            'approval_count' => $entry->approvalCount(),
            'new_retention_date' => $entry->new_retention_date?->toIso8601String(),
            'decided_at' => $entry->decided_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFormData(?RetentionPolicy $policy = null): array
    {
        return [
            'triggers' => array_combine(RetentionPolicy::TRIGGERS, [
                'matter_close' => 'Matter close + period',
                'document_date' => 'Document date + period',
                'fixed_date' => 'Fixed date + period',
            ]),
            'dispositions' => array_combine(RetentionPolicy::DISPOSITIONS, [
                'destroy' => 'Destroy (dual-control)',
                'review' => 'Review (manual)',
                'archive' => 'Archive to cold storage',
            ]),
            'units' => array_combine(RetentionPolicy::PERIOD_UNITS, [
                'days' => 'Days',
                'months' => 'Months',
                'years' => 'Years',
            ]),
            'categories' => array_combine(
                RetentionPolicy::CATEGORIES,
                array_map(fn (string $c): string => ucfirst(str_replace('_', ' ', $c)), RetentionPolicy::CATEGORIES)
            ),
            'matterTypes' => array_combine(
                Matter::MATTER_TYPES,
                array_map(fn (string $t): string => ucfirst(str_replace('_', ' ', $t)), Matter::MATTER_TYPES)
            ),
            'policy' => $policy,
        ];
    }

    // ── policies ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse|View
    {
        $admin = $this->admin($request);

        $policies = RetentionPolicy::query()
            ->where('org_id', $admin->org_id)
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $policies->map(fn (RetentionPolicy $p): array => $this->policyPayload($p)),
            ]);
        }

        return view('retention.policies.index', [
            'policies' => $policies,
        ]);
    }

    public function create(Request $request): View
    {
        $this->admin($request);

        return view('retention.policies.create', $this->policyFormData());
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($this->policyRules());

        $policy = RetentionService::createPolicy($admin, $validated);

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->policyPayload($policy)], 201);
        }

        return redirect()
            ->route('admin.retention.policies.show', $policy)
            ->with('status', 'Policy draft created.');
    }

    public function show(Request $request, string $policy): JsonResponse|View
    {
        $admin = $this->admin($request);
        $policy = $this->policyFor($admin, $policy);

        $versions = RetentionPolicy::query()
            ->where('org_id', $admin->org_id)
            ->where('name', $policy->name)
            ->orderByDesc('version')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $this->policyPayload($policy),
                'versions' => $versions->map(fn (RetentionPolicy $p): array => $this->policyPayload($p)),
            ]);
        }

        return view('retention.policies.show', [
            'policy' => $policy,
            'versions' => $versions,
        ]);
    }

    public function edit(Request $request, string $policy): View
    {
        $admin = $this->admin($request);
        $policy = $this->policyFor($admin, $policy);

        return view('retention.policies.edit', $this->policyFormData($policy));
    }

    /**
     * Editing a policy creates a NEW draft version; the edited row is
     * retained untouched (DOC-27 versioning).
     */
    public function update(Request $request, string $policy): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);
        $policy = $this->policyFor($admin, $policy);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($this->policyRules());

        $version = RetentionService::newPolicyVersion($admin, $policy, $validated);

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->policyPayload($version)], 201);
        }

        return redirect()
            ->route('admin.retention.policies.show', $version)
            ->with('status', "Saved as version {$version->version} (draft). Activate it to put it in force.");
    }

    public function activate(Request $request, string $policy): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);
        $policy = $this->policyFor($admin, $policy);

        $policy = RetentionService::activatePolicy($admin, $policy);

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->policyPayload($policy)]);
        }

        return redirect()
            ->route('admin.retention.policies.show', $policy)
            ->with('status', "Policy v{$policy->version} is now active.");
    }

    /**
     * Read-only simulator: which existing documents this policy would
     * affect. No flags, no queue rows — pure SELECTs.
     */
    public function simulate(Request $request, string $policy): JsonResponse|View
    {
        $admin = $this->admin($request);
        $policy = $this->policyFor($admin, $policy);

        $result = RetentionService::simulate($policy);

        if ($request->wantsJson() || $request->isMethod('post')) {
            return response()->json([
                'data' => [
                    'policy_id' => (string) $policy->getKey(),
                    'count' => $result['count'],
                    'documents' => $result['documents'],
                ],
            ]);
        }

        return view('retention.policies.show', [
            'policy' => $policy,
            'versions' => RetentionPolicy::query()
                ->where('org_id', $admin->org_id)
                ->where('name', $policy->name)
                ->orderByDesc('version')
                ->get(),
            'simulation' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function policyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:120'],
            'matter_type' => ['nullable', 'string', Rule::in(Matter::MATTER_TYPES)],
            'trigger' => ['required', 'string', Rule::in(RetentionPolicy::TRIGGERS)],
            'disposition' => ['required', 'string', Rule::in(RetentionPolicy::DISPOSITIONS)],
            'legal_basis' => ['required', 'string', 'max:2000'],
            'period_amount' => ['required', 'integer', 'min:1', 'max:100'],
            'period_unit' => ['required', 'string', Rule::in(RetentionPolicy::PERIOD_UNITS)],
            'fixed_date' => ['nullable', 'date'],
        ];
    }

    // ── disposition queue ─────────────────────────────────────

    public function dispositionIndex(Request $request): JsonResponse|View
    {
        $admin = $this->admin($request);

        $entries = DispositionQueue::query()
            ->whereHas('document', fn ($q) => $q->where('org_id', $admin->org_id))
            ->with(['document:id,title,matter_id', 'requester:id,name'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(50);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $entries->getCollection()->map(fn (DispositionQueue $e): array => $this->queuePayload($e)),
                'meta' => [
                    'current_page' => $entries->currentPage(),
                    'total' => $entries->total(),
                ],
            ]);
        }

        return view('retention.disposition.index', ['entries' => $entries]);
    }

    public function dispositionShow(Request $request, string $entry): JsonResponse|View
    {
        $admin = $this->admin($request);
        $entry = $this->queueEntryFor($admin, $entry);
        $entry->load(['document', 'requester:id,name']);

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->queuePayload($entry)]);
        }

        $approverNames = User::query()
            ->whereIn('id', $entry->approverIds())
            ->pluck('name', 'id');

        return view('retention.disposition.show', [
            'entry' => $entry,
            'approverNames' => $approverNames,
        ]);
    }

    public function dispositionStore(Request $request): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'document_id' => ['required', 'string'],
            'action' => ['required', 'string', Rule::in(DispositionQueue::ACTIONS)],
            'reason' => ['required', 'string', 'max:2000'],
            'new_retention_date' => ['nullable', 'date'],
        ]);

        /** @var Document|null $document */
        $document = Document::query()
            ->where('org_id', $admin->org_id)
            ->whereKey($validated['document_id'])
            ->first();

        abort_if($document === null, 404);

        /** @var Document $document */
        $entry = RetentionService::requestDisposition(
            $admin,
            $document,
            (string) $validated['action'],
            isset($validated['new_retention_date']) ? (string) $validated['new_retention_date'] : null,
            (string) $validated['reason']
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->queuePayload($entry->refresh())], 201);
        }

        return redirect()
            ->route('admin.retention.disposition.show', $entry)
            ->with('status', 'Disposition requested — pending decision.');
    }

    public function dispositionApprove(Request $request, string $entry): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);
        $entry = $this->queueEntryFor($admin, $entry);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $entry = RetentionService::approveDisposition(
            $admin,
            $entry,
            isset($validated['note']) ? (string) $validated['note'] : null
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->queuePayload($entry)]);
        }

        return redirect()
            ->route('admin.retention.disposition.index')
            ->with('status', $entry->status === 'executed'
                ? 'Disposition executed.'
                : 'Approval recorded — destroy needs two distinct approvers.');
    }

    public function dispositionReject(Request $request, string $entry): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);
        $entry = $this->queueEntryFor($admin, $entry);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $entry = RetentionService::rejectDisposition($admin, $entry, (string) $validated['reason']);

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->queuePayload($entry)]);
        }

        return redirect()
            ->route('admin.retention.disposition.index')
            ->with('status', 'Disposition rejected.');
    }

    // ── holds ─────────────────────────────────────────────────

    public function holdsIndex(Request $request): JsonResponse|View
    {
        $admin = $this->admin($request);

        $holds = LegalHoldService::activeHoldsForOrg((string) $admin->org_id);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $holds->map(fn (LegalHold $h): array => [
                    'id' => (string) $h->getKey(),
                    'matter_id' => $h->matter_id === null ? null : (string) $h->matter_id,
                    'matter_title' => $h->matter?->title,
                    'document_id' => $h->document_id === null ? null : (string) $h->document_id,
                    'document_title' => $h->document?->title,
                    'reason' => $h->reason,
                    'created_by' => $h->creator?->name,
                    'created_at' => $h->created_at?->toIso8601String(),
                ]),
            ]);
        }

        return view('retention.holds.index', ['holds' => $holds]);
    }

    /**
     * Release a hold from the admin hold list. Requires the legal-hold
     * role + a logged reason — 403 otherwise (DOC-28).
     */
    public function holdRelease(Request $request, string $hold): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);

        /** @var LegalHold|null $holdModel */
        $holdModel = LegalHold::query()
            ->where('org_id', $admin->org_id)
            ->whereKey($hold)
            ->first();

        abort_if($holdModel === null, 404);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        // Releasing a hold requires the legal-hold role — 403 otherwise
        // (DOC-28). Same posture as the template-editor 403 in T-04.
        if (! $admin->hasRole('legal_hold')) {
            if ($request->wantsJson()) {
                return response()->json(
                    ['code' => 'forbidden', 'reason' => 'legal_hold_role_required'],
                    403
                );
            }

            abort(403, 'Releasing a legal hold requires the legal-hold role.');
        }

        LegalHoldService::releaseHold($admin, $holdModel, (string) $validated['reason']);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['released' => true]]);
        }

        return redirect()
            ->route('admin.retention.holds.index')
            ->with('status', 'Legal hold released.');
    }
}
