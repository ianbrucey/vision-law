<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use App\Services\LegalHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Legal hold placement/release on the matter-nested routes (spec 007
 * T-09, DOC-28; 03-contract.md §Retention).
 *
 * Every route carries matter.access:manage at the middleware; placing or
 * releasing additionally requires the legal-hold role (403 otherwise).
 * Release requires a logged reason.
 *
 * A hold on a document (or its matter) blocks hard delete (423
 * hold_locked, audited as document.destroy.denied), version purge, and
 * matter archival until released.
 */
class DocumentHoldController extends Controller
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * The matter is resolved by the RequireMatterAccess middleware
     * (:manage); re-resolve it here for the service call.
     */
    private function matter(Request $request, string $matterId): Matter
    {
        /** @var Matter|null $matter */
        $matter = Matter::query()->whereKey($matterId)->first();

        abort_if($matter === null, 404);

        /** @var Matter $matter */
        return $matter;
    }

    private function document(Matter $matter, string $documentId): Document
    {
        // withTrashed: a hold can be placed or released on a trashed
        // document (holds block the 30-day purge, so this path matters).
        /** @var Document|null $document */
        $document = Document::query()
            ->withTrashed()
            ->where('matter_id', $matter->getKey())
            ->whereKey($documentId)
            ->first();

        // 006 convention: existence of a foreign/missing document is 404,
        // never 403 (no existence leak).
        abort_if($document === null, 404);

        /** @var Document $document */
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function holdPayload(LegalHold $hold): array
    {
        return [
            'id' => (string) $hold->getKey(),
            'matter_id' => $hold->matter_id === null ? null : (string) $hold->matter_id,
            'document_id' => $hold->document_id === null ? null : (string) $hold->document_id,
            'reason' => $hold->reason,
            'created_at' => $hold->created_at?->toIso8601String(),
        ];
    }

    private function requireLegalHoldRole(User $actor, Request $request): ?JsonResponse
    {
        if ($actor->hasRole('legal_hold')) {
            return null;
        }

        if ($request->wantsJson()) {
            return response()->json(
                ['code' => 'forbidden', 'reason' => 'legal_hold_role_required'],
                403
            );
        }

        abort(403, 'Placing a legal hold requires the legal-hold role.');
    }

    // ── document-level ────────────────────────────────────────

    public function store(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        if (($denied = $this->requireLegalHoldRole($actor, $request)) !== null) {
            return $denied;
        }

        $matterModel = $this->matter($request, $matter);
        $doc = $this->document($matterModel, $document);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $hold = LegalHoldService::placeDocumentHold(
            $actor,
            $matterModel,
            $doc,
            (string) $validated['reason']
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->holdPayload($hold)], 201);
        }

        return redirect()
            ->route('admin.retention.holds.index')
            ->with('status', 'Legal hold placed.');
    }

    public function release(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        if (($denied = $this->requireLegalHoldRole($actor, $request)) !== null) {
            return $denied;
        }

        $matterModel = $this->matter($request, $matter);
        $doc = $this->document($matterModel, $document);

        /** @var LegalHold|null $hold */
        $hold = LegalHold::query()
            ->active()
            ->where('org_id', $matterModel->org_id)
            ->where('document_id', $doc->getKey())
            ->first();

        abort_if($hold === null, 404);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        LegalHoldService::releaseHold($actor, $hold, (string) $validated['reason']);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['released' => true]]);
        }

        return redirect()
            ->route('admin.retention.holds.index')
            ->with('status', 'Legal hold released.');
    }

    // ── matter-level ──────────────────────────────────────────

    public function matterStore(Request $request, string $matter): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        if (($denied = $this->requireLegalHoldRole($actor, $request)) !== null) {
            return $denied;
        }

        $matterModel = $this->matter($request, $matter);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $hold = LegalHoldService::placeMatterHold(
            $actor,
            $matterModel,
            (string) $validated['reason']
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => $this->holdPayload($hold)], 201);
        }

        return redirect()
            ->route('admin.retention.holds.index')
            ->with('status', 'Matter-level legal hold placed.');
    }

    /**
     * Matter-level hold list (the document view's hold banner and the
     * retention UI read this).
     */
    public function matterHolds(Request $request, string $matter): JsonResponse
    {
        $this->actor($request);
        $matterModel = $this->matter($request, $matter);

        $holds = LegalHoldService::activeHoldsForMatter($matterModel);

        return response()->json([
            'data' => $holds->map(fn (LegalHold $hold): array => $this->holdPayload($hold)),
            'archival_blocked' => LegalHoldService::matterArchivalBlocked($matterModel),
        ]);
    }
}
