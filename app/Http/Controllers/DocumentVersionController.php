<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\DocumentIngestService;
use App\Services\DocumentSignedUrl;
use App\Services\DocumentUploadException;
use App\Services\DocumentVersioningService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Version history, rollback, and diff (spec 007 T-07, 03-contract.md
 * §Documents). All routes nest under /matters/{matter} and carry
 * RequireMatterAccess before any data access — the matter is read from
 * the request attributes, never re-resolved.
 *
 * - GET    /matters/{matter}/documents/{document}/versions            → index   (:view)
 * - POST   /matters/{matter}/documents/{document}/versions            → store   (:edit, upload new version)
 * - POST   /matters/{matter}/documents/{document}/versions/{version}/restore → restore (:view at the
 *          middleware so the controller can emit the specific
 *          document.version.restore.denied denial event; :edit enforced here)
 * - GET    /matters/{matter}/documents/{document}/versions/{a}/diff/{b} → diff (:view, a/b are version numbers)
 *
 * Per-row preview/download links are HMAC-signed URLs to T-03's
 * documents.preview.file / documents.download routes (15-min, scoped to
 * the user, permission re-checked per request). If those route names are
 * absent (T-03 not yet landed on this branch) the buttons render
 * disabled instead of 500ing.
 */
class DocumentVersionController extends Controller
{
    public function __construct(
        private readonly DocumentVersioningService $versioning,
        private readonly DocumentSignedUrl $signedUrl,
    ) {}

    // ------------------------------------------------------------------
    // Version history
    // ------------------------------------------------------------------

    /**
     * History view: all versions newest first, current distinguished,
     * per-row preview / download / restore / diff-against-current.
     */
    public function index(Request $request, string $matter, string $document): View
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if ($doc === null) {
            abort(404);
        }

        $versions = $doc->versions()
            ->with(['creator', 'restoredFrom', 'blob'])
            ->reorder('version_number', 'desc')
            ->get();

        $canEdit = $this->can($actor, 'edit', $matterModel);

        $rows = $versions->map(fn (DocumentVersion $version): array => [
            'version' => $version,
            'previewUrl' => $this->signedRoute('documents.preview.file', $matterModel, $doc, $actor, $version),
            'downloadUrl' => $this->signedRoute('documents.download', $matterModel, $doc, $actor, $version),
        ])->all();

        return view('documents.versions', [
            'matter' => $matterModel,
            'document' => $doc,
            'rows' => $rows,
            'canEdit' => $canEdit,
            'currentVersionId' => (string) $doc->current_version_id,
        ]);
    }

    // ------------------------------------------------------------------
    // Upload new version (uploaded binaries; C-07)
    // ------------------------------------------------------------------

    /**
     * "Upload new version": runs T-02's ingest pipeline (scan, metadata)
     * and appends version N+1. Byte-identical uploads are a no-op with a
     * notice — no new version is created (DOC-18/C-10).
     */
    public function store(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if ($doc === null) {
            abort(404);
        }

        /** @var array{file: UploadedFile, change_note?: ?string} $validated */
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'change_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $file = $validated['file'];

        if (! $file->isValid()) {
            return $this->rejection($request, new DocumentUploadException('upload_invalid', 422, 'Uploaded file is not readable.'));
        }

        // 413 before storage (C-01): declared size first, then re-enforced
        // while streaming in case it lies.
        $max = (int) config('document.max_single_upload_bytes');
        $declaredSize = $file->getSize();

        if ($declaredSize !== false && $declaredSize > $max) {
            return $this->rejection($request, new DocumentUploadException('upload_too_large', 413, 'Upload exceeds the single-shot limit.'));
        }

        try {
            $bytes = $this->streamBytes($file, $max);
            $outcome = app(DocumentIngestService::class)->ingestNewVersion(
                $doc,
                $matterModel,
                $actor,
                $bytes,
                [
                    'change_note' => $validated['change_note'] ?? null,
                    'filename' => $file->getClientOriginalName(),
                ]
            );
        } catch (DocumentUploadException $e) {
            return $this->rejection($request, $e);
        }

        if ($outcome->isDuplicate()) {
            return $this->outcomeResponse(
                $request,
                $matterModel,
                $doc,
                $outcome->version,
                200,
                'identical_bytes_already_stored'
            );
        }

        if ($outcome->status === 'quarantined') {
            return $this->outcomeResponse(
                $request,
                $matterModel,
                $doc,
                $outcome->version,
                201,
                'quarantined'
            );
        }

        return $this->outcomeResponse($request, $matterModel, $doc, $outcome->version, 201, 'version_created');
    }

    // ------------------------------------------------------------------
    // Rollback
    // ------------------------------------------------------------------

    /**
     * Restore a prior version: creates version N+1 with the old version's
     * exact bytes (007-D06). Requires a non-empty reason; restoring the
     * current version is a 200 no-op with a notice.
     */
    public function restore(Request $request, string $matter, string $document, string $version): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if ($doc === null) {
            abort(404);
        }

        // Editor-gated. The route carries matter.access:view so THIS
        // check can audit the specific document.version.restore.denied
        // denial event (contract §Documents).
        try {
            AccessControl::authorize($actor, 'edit', $matterModel);
        } catch (AccessDeniedException) {
            AuditLogger::log('document.version.restore.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $doc->getKey(),
                'version_id' => $version,
            ], $matterModel);

            if ($request->expectsJson()) {
                return response()->json(['code' => 'forbidden'], 403);
            }

            abort(403);
        }

        $target = $this->resolveVersion($doc, $version);

        if ($target === null) {
            abort(404);
        }

        $reason = trim((string) $request->input('reason', ''));

        try {
            $result = $this->versioning->restoreVersion($doc, $target, $actor, $reason, $matterModel);
        } catch (DocumentUploadException $e) {
            return $this->rejection($request, $e);
        }

        $notice = $result['status'] === 'already_current' ? 'already_current_version' : 'version_restored';

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->versionResource($result['version']),
                'notice' => $notice,
            ]);
        }

        return redirect()
            ->route('documents.versions.index', [$matterModel->getKey(), $doc->getKey()])
            ->with('version_notice', $notice);
    }

    // ------------------------------------------------------------------
    // Structured diff
    // ------------------------------------------------------------------

    /**
     * Diff version {a} → {b} (version numbers, per contract). Unified
     * rendering with page hints; clean "diff unavailable" state for
     * image-only versions without extracted text (no OCR here — T-06).
     */
    public function diff(Request $request, string $matter, string $document, string $a, string $b): View
    {
        $matterModel = $this->authorizedMatter($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if ($doc === null) {
            abort(404);
        }

        $versionA = $doc->versions()->where('version_number', (int) $a)->first();
        $versionB = $doc->versions()->where('version_number', (int) $b)->first();

        if (! $versionA instanceof DocumentVersion || ! $versionB instanceof DocumentVersion) {
            abort(404);
        }

        return view('documents.version-diff', [
            'matter' => $matterModel,
            'document' => $doc,
            'versionA' => $versionA,
            'versionB' => $versionB,
            'diff' => $this->versioning->diff($versionA, $versionB),
        ]);
    }

    // ------------------------------------------------------------------
    // Private helpers (per-controller convention)
    // ------------------------------------------------------------------

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

    private function resolveDocument(Matter $matter, string $raw): ?Document
    {
        if (! Str::isUuid($raw)) {
            return null;
        }

        return Document::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->whereNull('deleted_at')
            ->first();
    }

    private function resolveVersion(Document $document, string $raw): ?DocumentVersion
    {
        if (! Str::isUuid($raw)) {
            return null;
        }

        return $document->versions()->whereKey($raw)->first();
    }

    private function can(User $actor, string $action, Matter $matter): bool
    {
        try {
            AccessControl::authorize($actor, $action, $matter);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    /**
     * Signed per-version preview/download URL, or null when the owning
     * route (T-03) is not registered — the view disables those buttons
     * instead of 500ing.
     */
    private function signedRoute(string $name, Matter $matter, Document $doc, User $actor, DocumentVersion $version): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        return route($name, [$matter->getKey(), $doc->getKey()])
            .'?'.$this->signedUrl->generate($actor, $doc, (string) $version->getKey());
    }

    /**
     * @return array{id: string, version_number: int, change_note: ?string, processing_status: string, size: ?int, created_at: string}|null
     */
    private function versionResource(?DocumentVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $blob = $version->blob()->first();

        return [
            'id' => (string) $version->getKey(),
            'version_number' => (int) $version->version_number,
            'change_note' => $version->change_note,
            'processing_status' => (string) $version->processing_status,
            'size' => $blob !== null ? (int) $blob->size : null,
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }

    private function outcomeResponse(
        Request $request,
        Matter $matter,
        Document $document,
        ?DocumentVersion $version,
        int $status,
        string $notice,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'data' => $this->versionResource($version),
                'notice' => $notice,
            ], $status);
        }

        return redirect()
            ->route('documents.versions.index', [$matter->getKey(), $document->getKey()])
            ->with('version_notice', $notice);
    }

    private function rejection(Request $request, DocumentUploadException $e): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => $e->errorCode], $e->httpStatus);
        }

        return redirect()->back()->withErrors(['file' => $e->getMessage()])->withInput();
    }

    /**
     * Stream the uploaded file with a hard size cap so an over-limit
     * body never reaches the store (413 before storage, C-01).
     *
     * @throws DocumentUploadException
     */
    private function streamBytes(UploadedFile $file, int $max): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new DocumentUploadException('upload_invalid', 422, 'Uploaded file is not readable.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DocumentUploadException('upload_invalid', 422, 'Uploaded file is not readable.');
        }

        $bytes = '';
        $size = 0;

        try {
            while (! feof($handle)) {
                $buf = fread($handle, 65536);

                if ($buf === false) {
                    break;
                }

                $size += strlen($buf);

                if ($size > $max) {
                    throw new DocumentUploadException('upload_too_large', 413, 'Upload exceeds the single-shot limit.');
                }

                $bytes .= $buf;
            }
        } finally {
            fclose($handle);
        }

        return $bytes;
    }
}
