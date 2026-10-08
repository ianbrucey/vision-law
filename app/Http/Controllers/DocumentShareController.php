<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentGrant;
use App\Models\DocumentShare;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentExportService;
use App\Services\DocumentShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Document sharing (spec 007 T-08, DOC-24/25/26; 03-contract.md §Sharing).
 *
 * - GET    /matters/{matter}/documents/{document}/share   → show    (:manage; share dialog)
 * - POST   /matters/{matter}/documents/{document}/grants  → storeGrant   (:manage)
 * - DELETE /matters/{matter}/documents/{document}/grants/{grant} → destroyGrant (:manage)
 * - POST   /matters/{matter}/documents/{document}/links   → storeLink    (:manage)
 * - DELETE /matters/{matter}/documents/{document}/links/{link}   → destroyLink  (:manage)
 * - POST   /matters/{matter}/documents/export            → export    (:edit)
 *
 * Leak rules (brief): share tokens and token hashes never reach logs or
 * views — link creation returns the one-time plaintext URL via a session
 * flash, never re-rendered. Denial bodies carry IDs only.
 */
class DocumentShareController extends Controller
{
    public function __construct(
        private readonly DocumentShareService $shares,
        private readonly DocumentExportService $exports,
    ) {}

    // ------------------------------------------------------------------
    // Share dialog
    // ------------------------------------------------------------------

    public function show(Request $request, string $matter, string $document): Response
    {
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);
        if ($doc === null) {
            abort(404);
        }

        $grants = DocumentGrant::query()
            ->where('document_id', $doc->getKey())
            ->with(['user:id,name,email', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();

        $links = DocumentShare::query()
            ->where('document_id', $doc->getKey())
            ->with('version:id,version_number')
            ->orderByDesc('created_at')
            ->get();

        $grantedUserIds = $grants->pluck('user_id')->all();
        $candidates = User::query()
            ->where('org_id', $matterModel->org_id)
            ->where('deactivated_at', null)
            ->whereNotIn('id', $grantedUserIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email']);

        return response()->view('documents.share', [
            'matter' => $matterModel,
            'document' => $doc,
            'grants' => $grants,
            'links' => $links,
            'candidates' => $candidates,
            'levels' => DocumentGrant::LEVELS,
            'versions' => $doc->versions()->orderByDesc('version_number')->get(['id', 'version_number']),
            // One-time plaintext link, flashed at creation — rendered
            // once, never persisted or re-rendered.
            'freshLink' => $request->session()->get('fresh_share_link'),
        ]);
    }

    // ------------------------------------------------------------------
    // Internal grants
    // ------------------------------------------------------------------

    public function storeGrant(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);
        if ($doc === null) {
            abort(404);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('org_id', $matterModel->org_id)],
            'level' => ['required', Rule::in(DocumentGrant::LEVELS)],
        ]);

        $grantee = User::findOrFail((string) $validated['user_id']);

        try {
            $result = $this->shares->grant($actor, $doc, $grantee, $validated['level']);
        } catch (\InvalidArgumentException $e) {
            abort(response()->json(['code' => 'validation', 'details' => ['level' => [$e->getMessage()]]], 422));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'data' => [
                    'id' => (string) $result['grant']->getKey(),
                    'user_id' => (string) $grantee->getKey(),
                    'level' => $result['grant']->level,
                ],
            ], $result['created'] ? 201 : 200);
        }

        return redirect()->route('documents.share', [$matterModel, $doc]);
    }

    public function destroyGrant(Request $request, string $matter, string $document, string $grant): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);
        if ($doc === null) {
            abort(404);
        }

        $grantModel = DocumentGrant::query()
            ->where('document_id', $doc->getKey())
            ->whereKey($grant)
            ->first();
        if ($grantModel === null) {
            abort(404);
        }

        $this->shares->revokeGrant($actor, $grantModel);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['revoked' => true]]);
        }

        return redirect()->route('documents.share', [$matterModel, $doc]);
    }

    // ------------------------------------------------------------------
    // External links
    // ------------------------------------------------------------------

    public function storeLink(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);
        if ($doc === null) {
            abort(404);
        }

        $maxDays = (int) config('document.sharing.link_expiry_max_days', 30);
        $validated = $request->validate([
            'expires_in_days' => ['sometimes', 'integer', 'min:1', "max:{$maxDays}"],
            'password' => ['nullable', 'string', 'min:'.(int) config('document.sharing.link_password_min_length', 12), 'max:72'],
            'allow_download' => ['sometimes', 'boolean'],
            'version_id' => ['nullable', 'uuid'],
        ]);

        try {
            $result = $this->shares->createLink($actor, $doc, [
                'expires_in_days' => $validated['expires_in_days'] ?? null,
                'password' => $validated['password'] ?? null,
                'allow_download' => $validated['allow_download'] ?? true,
                'version_id' => $validated['version_id'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            abort(response()->json(['code' => 'validation', 'details' => ['link' => [$e->getMessage()]]], 422));
        }

        // The plaintext token is shown exactly once (contract §Component
        // props): flashed to the session, rendered once by the dialog,
        // never stored. The token itself never reaches the audit log.
        $url = route('shares.show', ['token' => $result['token']]);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['url' => $url, 'expires_at' => $result['share']->expires_at->toIso8601String()]], 201);
        }

        return redirect()
            ->route('documents.share', [$matterModel, $doc])
            ->with('fresh_share_link', $url);
    }

    public function destroyLink(Request $request, string $matter, string $document, string $link): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);
        if ($doc === null) {
            abort(404);
        }

        $share = DocumentShare::query()
            ->where('document_id', $doc->getKey())
            ->whereKey($link)
            ->first();
        if ($share === null) {
            abort(404);
        }

        $this->shares->revokeLink($actor, $share);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['revoked' => true]]);
        }

        return redirect()->route('documents.share', [$matterModel, $doc]);
    }

    // ------------------------------------------------------------------
    // Encrypted export
    // ------------------------------------------------------------------

    public function export(Request $request, string $matter): BinaryFileResponse|JsonResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->authorizedMatter($request);

        $validated = $request->validate([
            'document_ids' => ['sometimes', 'array', 'max:500'],
            'document_ids.*' => ['uuid'],
            'folder_ids' => ['sometimes', 'array', 'max:100'],
            'folder_ids.*' => ['uuid'],
            'password' => ['required', 'string', 'min:'.(int) config('document.sharing.export_password_min_length', 12), 'max:128'],
        ]);

        try {
            $package = $this->exports->export(
                $actor,
                $matterModel,
                array_values(array_unique($validated['document_ids'] ?? [])),
                array_values(array_unique($validated['folder_ids'] ?? [])),
                $validated['password']
            );
        } catch (AccessDeniedException $e) {
            abort(response()->json(['code' => $e->httpStatus === 404 ? 'not_found' : 'forbidden'], $e->httpStatus));
        } catch (\InvalidArgumentException $e) {
            abort(response()->json(['code' => 'validation', 'details' => ['export' => [$e->getMessage()]]], 422));
        }

        return response()->download(
            $package['path'],
            $package['filename'],
            ['Content-Type' => 'application/zip']
        )->deleteFileAfterSend(true);
    }

    // ------------------------------------------------------------------
    // Helpers (T-05 DocumentController conventions)
    // ------------------------------------------------------------------

    private function resolveDocument(Matter $matter, string $document): ?Document
    {
        if (! Str::isUuid($document)) {
            return null;
        }

        return Document::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($document)
            ->first();
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
}
