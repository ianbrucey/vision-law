<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DocumentFilingException;
use App\Models\Document;
use App\Models\DocumentSavedView;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\DocumentFilingService;
use App\Services\DocumentFolderService;
use App\Services\DocumentQueryService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Document filing endpoints (spec 007 T-05, DOC-11/12/13/14).
 *
 * - GET    /matters/{matter}/documents                    → index   (:view; HTML screen + JSON)
 * - GET    /matters/{matter}/documents/trash              → trash   (:manage; per-matter trash)
 * - POST   /matters/{matter}/documents/bulk/move          → bulkMove   (:edit)
 * - POST   /matters/{matter}/documents/bulk/tag           → bulkTag    (:edit)
 * - POST   /matters/{matter}/documents/bulk/download      → bulkDownload (:view; streamed ZIP)
 * - GET    /matters/{matter}/documents/{document}         → show    (:view; JSON resource)
 * - PATCH  /matters/{matter}/documents/{document}         → update  (:edit; metadata only)
 * - DELETE /matters/{matter}/documents/{document}         → destroy (:manage; → trash)
 * - DELETE /matters/{matter}/documents/{document}/permanent → destroyPermanent (:manage; 423 on hold)
 * - POST   /matters/{matter}/documents/{document}/restore → restore (:manage)
 * - POST   /matters/{matter}/documents/{document}/move    → move    (:edit; folder or matter)
 * - GET/POST/DELETE /matters/{matter}/document-views…     → saved views (:view, own only)
 *
 * Every route carries RequireMatterAccess BEFORE any data access; the
 * authorized matter is read from request attributes, never re-resolved.
 * Leak rules (brief): titles/filenames never reach denied actors — the
 * middleware 404s before any data access, and denial payloads carry IDs
 * only. Document rows themselves are created by T-02's upload pipeline;
 * this controller only files, lists, and retires them.
 */
class DocumentController extends Controller
{
    // ── List ──────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse|Response
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        DocumentFolderService::ensureDefaults($matter);

        $filters = $this->filtersFromRequest($request);

        // Saved view application: ?view_id=<uuid> merges the stored
        // filters under the request's explicit filters (request wins).
        $activeViewId = null;
        $viewId = $request->query('view_id');
        if (is_string($viewId) && Str::isUuid($viewId)) {
            $view = DocumentSavedView::query()
                ->where('user_id', $actor->getKey())
                ->whereKey($viewId)
                ->first();
            if ($view !== null) {
                $activeViewId = (string) $view->getKey();
                $filters = array_merge((array) $view->filters, array_filter($filters, fn ($v) => $v !== null && $v !== ''));
            }
        }

        $paginator = DocumentQueryService::paginate($matter, $filters);
        $facets = DocumentQueryService::facets($matter);
        $folders = DocumentFolderService::tree($matter);
        $savedViews = DocumentSavedView::query()
            ->where('user_id', $actor->getKey())
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $paginator->getCollection()->map(
                    fn (Document $d): array => $this->resource($d)
                )->values()->all(),
                'meta' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
                'facets' => $facets,
                'saved_views' => $savedViews->map(
                    fn (DocumentSavedView $v): array => ['id' => (string) $v->getKey(), 'name' => $v->name]
                )->values()->all(),
                'filters' => $filters,
            ]);
        }

        return response()->view('documents.index', [
            'matter' => $matter,
            'documents' => $paginator,
            'facets' => $facets,
            'folders' => $folders,
            'savedViews' => $savedViews,
            'filters' => $filters,
            'activeViewId' => $activeViewId,
            'canEdit' => $this->can($actor, 'edit', $matter),
            'canManage' => $this->can($actor, 'manage', $matter),
        ]);
    }

    // ── Trash ─────────────────────────────────────────────────────────

    /**
     * Per-matter trash: soft-deleted documents awaiting the 30-day purge.
     * Visible only to users with matter delete permission (:manage).
     */
    public function trash(Request $request): JsonResponse|Response
    {
        $matter = $this->authorizedMatter($request);

        $trashed = Document::query()
            ->onlyTrashed()
            ->where('matter_id', $matter->getKey())
            ->with(['folder:id,name', 'currentVersion:id,document_id,version_number,created_at'])
            ->withCount('versions')
            ->orderByDesc('deleted_at')
            ->paginate(DocumentQueryService::PER_PAGE)
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => $trashed->getCollection()->map(
                    fn (Document $d): array => $this->resource($d)
                )->values()->all(),
                'meta' => [
                    'total' => $trashed->total(),
                    'per_page' => $trashed->perPage(),
                    'current_page' => $trashed->currentPage(),
                    'last_page' => $trashed->lastPage(),
                    'retention_days' => DocumentFilingService::TRASH_RETENTION_DAYS,
                ],
            ]);
        }

        return response()->view('documents.trash', [
            'matter' => $matter,
            'documents' => $trashed,
            'retentionDays' => DocumentFilingService::TRASH_RETENTION_DAYS,
        ]);
    }

    // ── Detail / metadata ─────────────────────────────────────────────

    public function show(Request $request, string $matter, string $document): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);

        return response()->json(['data' => $this->resource($this->resolveDocument($matterModel, $document))]);
    }

    /**
     * Metadata-only update (DOC-19): title, description, tags, folder.
     * Never creates a version.
     */
    public function update(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        /** @var array{title?: string, description?: string|null, tags?: list<string>, folder_id?: string|null} $validated */
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array', 'max:25'],
            'tags.*' => ['string', 'max:64'],
            'folder_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $folderChanged = array_key_exists('folder_id', $validated);
        $folderId = $validated['folder_id'] ?? null;
        if ($folderChanged && $folderId !== null && ! Str::isUuid($folderId)) {
            throw ValidationException::withMessages(['folder_id' => ['Invalid folder id.']]);
        }
        if ($folderChanged && is_string($folderId)) {
            // 422 folder_not_in_matter when the folder belongs elsewhere.
            try {
                DocumentFolderService::resolveInMatter($matterModel, $folderId);
            } catch (ModelNotFoundException $e) {
                throw new DocumentFilingException(422, 'folder_not_in_matter', [
                    'folder_id' => $folderId,
                ], previous: $e);
            }
        }

        $tags = null;
        if (array_key_exists('tags', $validated)) {
            /** @var list<string> $rawTags */
            $rawTags = $validated['tags'];
            $tags = array_values(array_unique(array_filter(array_map(
                fn (string $t): string => mb_substr(trim($t), 0, 64),
                $rawTags
            ))));
        }

        DB::transaction(function () use ($matterModel, $doc, $actor, $validated, $folderChanged, $folderId, $tags): void {
            if (array_key_exists('title', $validated)) {
                $doc->title = trim((string) $validated['title']);
            }
            if (array_key_exists('description', $validated)) {
                $doc->description = $validated['description'];
            }
            if ($folderChanged) {
                $doc->folder_id = $folderId;
            }
            if ($tags !== null) {
                // Native text[] via the PostgresTextArray cast (007 T-02).
                $doc->tags = $tags;
            }
            $doc->save();

            AuditLogger::log('document.metadata.updated', $actor, [
                'document_id' => (string) $doc->getKey(),
                'matter_id' => (string) $matterModel->getKey(),
            ], $matterModel);
        });

        $doc->refresh();

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Document updated.',
            ]);
        }

        return response()->json(['data' => $this->resource($doc)]);
    }

    // ── Filing: move ──────────────────────────────────────────────────

    /**
     * File a document: move between folders of this matter
     * ({folder_id}), or re-file to another matter ({matter_id}, with
     * optional {folder_id} in the destination).
     */
    public function move(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        /** @var array{folder_id?: string|null, matter_id?: string|null} $validated */
        $validated = $request->validate([
            'folder_id' => ['nullable', 'string'],
            'matter_id' => ['nullable', 'string'],
        ]);

        foreach (['folder_id', 'matter_id'] as $key) {
            if (($validated[$key] ?? null) !== null && ! Str::isUuid($validated[$key])) {
                throw ValidationException::withMessages([$key => ['Invalid id.']]);
            }
        }

        $destMatterId = $validated['matter_id'] ?? null;

        if ($destMatterId !== null) {
            $destination = Matter::query()->whereKey($destMatterId)->first();

            if (! $destination instanceof Matter) {
                // 404 — never confirm or deny a foreign matter's existence.
                throw new ModelNotFoundException;
            }

            try {
                $doc = DocumentFilingService::moveToMatter(
                    $matterModel,
                    $doc,
                    $actor,
                    $destination,
                    $validated['folder_id'] ?? null
                );
            } catch (ModelNotFoundException $e) {
                throw new DocumentFilingException(422, 'folder_not_in_matter', [], previous: $e);
            }
        } else {
            try {
                $doc = DocumentFilingService::moveToFolder(
                    $matterModel,
                    $doc,
                    $actor,
                    $validated['folder_id'] ?? null
                );
            } catch (ModelNotFoundException $e) {
                throw new DocumentFilingException(422, 'folder_not_in_matter', [], previous: $e);
            }
        }

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => $destMatterId !== null ? 'Document re-filed to the selected matter.' : 'Document moved.',
            ]);
        }

        return response()->json(['data' => $this->resource($doc)]);
    }

    // ── Trash / restore / destroy ─────────────────────────────────────

    public function destroy(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        $doc = DocumentFilingService::trash($matterModel, $doc, $actor);

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Document moved to trash.',
            ]);
        }

        return response()->json(['data' => $this->resource($doc)]);
    }

    public function restore(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document, true);

        $doc = DocumentFilingService::restore($matterModel, $doc, $actor);

        if (! $request->wantsJson()) {
            return redirect()->route('documents.trash', ['matter' => $matterModel->getKey()])->with('toast', [
                'tone' => 'ok',
                'message' => 'Document restored with all versions.',
            ]);
        }

        return response()->json(['data' => $this->resource($doc)]);
    }

    /**
     * Immediate hard delete. Legal hold → 423 hold_locked +
     * document.destroy.denied (DOC-28).
     */
    public function destroyPermanent(Request $request, string $matter, string $document): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document, true);

        DocumentFilingService::hardDelete($matterModel, $doc, $actor);

        if (! $request->wantsJson()) {
            return redirect()->route('documents.trash', ['matter' => $matterModel->getKey()])->with('toast', [
                'tone' => 'ok',
                'message' => 'Document permanently deleted.',
            ]);
        }

        return response()->json(['code' => 'ok']);
    }

    // ── Bulk ──────────────────────────────────────────────────────────

    public function bulkMove(Request $request, string $matter): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{document_ids: list<string>, folder_id: string} $validated */
        $validated = $request->validate([
            'document_ids' => ['required', 'array', 'min:1', 'max:200'],
            'document_ids.*' => ['string', 'uuid'],
            'folder_id' => ['required', 'string', 'uuid'],
        ]);

        try {
            $moved = DocumentFilingService::bulkMove($matterModel, $validated['document_ids'], $actor, $validated['folder_id']);
        } catch (ModelNotFoundException $e) {
            throw new DocumentFilingException(422, 'folder_not_in_matter', [], previous: $e);
        }

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => "{$moved} documents moved.",
            ]);
        }

        return response()->json(['code' => 'ok', 'moved' => $moved]);
    }

    public function bulkTag(Request $request, string $matter): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{document_ids: list<string>, tags: mixed, mode: string} $validated */
        $validated = $request->validate([
            'document_ids' => ['required', 'array', 'min:1', 'max:200'],
            'document_ids.*' => ['string', 'uuid'],
            // The HTML form posts a comma-separated string; JSON clients
            // may post a list. Both normalize to a list below.
            'tags' => ['required'],
            'mode' => ['required', Rule::in(['add', 'remove'])],
        ]);

        $rawTags = $validated['tags'];
        if (! is_string($rawTags) && ! is_array($rawTags)) {
            throw ValidationException::withMessages(['tags' => ['Tags must be a list or a comma-separated string.']]);
        }
        /** @var list<string> $tags */
        $tags = is_string($rawTags) ? explode(',', $rawTags) : array_values($rawTags);
        $tagged = DocumentFilingService::bulkTag(
            $matterModel,
            $validated['document_ids'],
            $actor,
            $tags,
            $validated['mode']
        );

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => "{$tagged} documents updated.",
            ]);
        }

        return response()->json(['code' => 'ok', 'tagged' => $tagged]);
    }

    /**
     * Bulk download: streams a ZIP of current versions, preserving folder
     * structure (DOC-11).
     */
    public function bulkDownload(Request $request, string $matter): Response|JsonResponse|BinaryFileResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{document_ids: list<string>} $validated */
        $validated = $request->validate([
            'document_ids' => ['required', 'array', 'min:1', 'max:200'],
            'document_ids.*' => ['string', 'uuid'],
        ]);

        $zipPath = DocumentFilingService::bulkDownloadZip($matterModel, $validated['document_ids'], $actor);

        $filename = 'documents-'.now()->format('Ymd-His').'.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    // ── Saved views ───────────────────────────────────────────────────

    public function indexViews(Request $request): JsonResponse
    {
        $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $views = DocumentSavedView::query()
            ->where('user_id', $actor->getKey())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $views->map(fn (DocumentSavedView $v): array => [
                'id' => (string) $v->getKey(),
                'name' => $v->name,
                'filters' => $v->filters,
            ])->values()->all(),
        ]);
    }

    public function storeView(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{name: string, filters: array<string, mixed>} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['required', 'array'],
        ]);

        $filters = array_intersect_key(
            $validated['filters'],
            array_fill_keys(DocumentQueryService::FILTER_KEYS, true)
        );
        // Drop empties so a saved view stays a clean filter bundle.
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        $view = DocumentSavedView::query()->updateOrCreate(
            ['user_id' => $actor->getKey(), 'name' => trim($validated['name'])],
            ['filters' => $filters]
        );

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'View saved.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => (string) $view->getKey(),
                'name' => $view->name,
                'filters' => $view->filters,
            ],
        ], 201);
    }

    public function destroyView(Request $request, string $matter, string $view): JsonResponse|RedirectResponse
    {
        $this->authorizedMatter($request);
        $actor = $this->actor($request);

        if (! Str::isUuid($view)) {
            throw new ModelNotFoundException;
        }

        // Own views only — a foreign id reads as missing (no enumeration).
        $viewModel = DocumentSavedView::query()
            ->where('user_id', $actor->getKey())
            ->whereKey($view)
            ->firstOrFail();
        $viewModel->delete();

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'View deleted.',
            ]);
        }

        return response()->json(['code' => 'ok']);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function resource(Document $document): array
    {
        $version = $document->currentVersion;

        // Larastan infers 'datetime' casts as string|null repo-wide (the
        // models pin real types via @property docblocks); the Eloquent
        // cast returns Carbon at runtime.
        /** @var CarbonInterface|null $retentionFlaggedAt */
        $retentionFlaggedAt = $document->retention_flagged_at;

        return [
            'id' => (string) $document->getKey(),
            'title' => $document->title,
            'description' => $document->description,
            'tags' => DocumentQueryService::tagsOf($document),
            'kind' => $document->kind,
            'status' => $document->status,
            'metadata_status' => $document->metadata_status,
            'folder_id' => $document->folder_id !== null ? (string) $document->folder_id : null,
            'folder_name' => $document->relationLoaded('folder') && $document->folder !== null
                ? $document->folder->name
                : null,
            'version_count' => (int) ($document->getAttribute('versions_count')
                ?? $document->versions()->count()),
            'current_version' => $version !== null ? [
                'id' => (string) $version->getKey(),
                'version_number' => $version->version_number,
                'page_count' => $version->page_count,
                'created_at' => $version->created_at->toIso8601String(),
            ] : null,
            'created_by' => (string) $document->created_by,
            'uploader_name' => $document->relationLoaded('creator') && $document->creator !== null
                ? $document->creator->name
                : null,
            'retention_flagged_at' => $retentionFlaggedAt?->toIso8601String(),
            'deleted_at' => $document->deleted_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $only
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request, array $only = []): array
    {
        $keys = $only === [] ? DocumentQueryService::FILTER_KEYS : $only;
        $filters = [];

        foreach ($keys as $key) {
            $value = $request->input($key);
            $filters[$key] = $value === '' ? null : $value;
        }

        return $filters;
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveDocument(Matter $matter, string $raw, bool $withTrashed = false): Document
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        $query = Document::query()->where('matter_id', $matter->getKey())->whereKey($raw);

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
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

    private function can(User $actor, string $action, Matter $matter): bool
    {
        try {
            AccessControl::authorize($actor, $action, $matter);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
