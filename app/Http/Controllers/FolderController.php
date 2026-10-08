<?php

namespace App\Http\Controllers;

use App\Exceptions\DocumentFilingException;
use App\Models\DocumentFolder;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentFolderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-matter folder tree endpoints (spec 007 T-05, DOC-11).
 *
 * - GET    /matters/{matter}/folders            → index   (matter.access:view)
 * - POST   /matters/{matter}/folders            → store   (matter.access:edit)
 * - PATCH  /matters/{matter}/folders/{folder}   → update  (matter.access:edit)
 * - DELETE /matters/{matter}/folders/{folder}   → destroy (matter.access:edit, empty-only)
 *
 * Every route carries RequireMatterAccess BEFORE any data access; the
 * authorized matter is read from request attributes, never re-resolved.
 * Folders are always scoped to that matter (leak-safe 404s for foreign
 * ids via DocumentFolderService::resolveInMatter).
 */
class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);

        DocumentFolderService::ensureDefaults($matter);

        return response()->json([
            'data' => DocumentFolderService::tree($matter)->map(
                fn (DocumentFolder $folder): array => $this->node($folder)
            )->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{name: string, parent_id?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'string'],
        ]);

        if (($validated['parent_id'] ?? null) !== null && ! Str::isUuid($validated['parent_id'])) {
            throw ValidationException::withMessages(['parent_id' => ['Invalid folder id.']]);
        }

        // An empty selection means "top level".
        $parentId = ($validated['parent_id'] ?? null) !== '' ? ($validated['parent_id'] ?? null) : null;

        try {
            $folder = DocumentFolderService::create(
                $matter,
                $actor,
                trim($validated['name']),
                $parentId
            );
        } catch (ModelNotFoundException $e) {
            throw new DocumentFilingException(422, 'folder_not_in_matter', [], previous: $e);
        }

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Folder created.',
            ]);
        }

        return response()->json(['data' => $this->node($folder)], 201);
    }

    public function update(Request $request, string $matter, string $folder): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $folderModel = $this->resolveFolder($matterModel, $folder);

        /** @var array{name?: string, parent_id?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $parentId = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : '__unchanged__';
        if ($parentId !== '__unchanged__') {
            // An empty selection detaches the folder to the top level.
            if ($parentId === '') {
                $parentId = null;
            }
            if ($parentId !== null && ! Str::isUuid($parentId)) {
                throw ValidationException::withMessages(['parent_id' => ['Invalid folder id.']]);
            }
        }

        try {
            $updated = DocumentFolderService::update(
                $matterModel,
                $folderModel,
                $actor,
                isset($validated['name']) ? trim($validated['name']) : null,
                $parentId === '__unchanged__' ? null : $parentId,
                $parentId === null // explicit null detaches to root
            );
        } catch (ModelNotFoundException $e) {
            throw new DocumentFilingException(422, 'folder_not_in_matter', [], previous: $e);
        }

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Folder updated.',
            ]);
        }

        return response()->json(['data' => $this->node($updated)]);
    }

    public function destroy(Request $request, string $matter, string $folder): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $folderModel = $this->resolveFolder($matterModel, $folder);

        DocumentFolderService::delete($matterModel, $folderModel, $actor);

        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Folder deleted.',
            ]);
        }

        return response()->json(['code' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(DocumentFolder $folder): array
    {
        return [
            'id' => (string) $folder->getKey(),
            'name' => $folder->name,
            'parent_id' => $folder->parent_id !== null ? (string) $folder->parent_id : null,
            'document_count' => (int) ($folder->getAttribute('document_count') ?? 0),
            'children' => $folder->relationLoaded('children')
                ? $folder->children->map(fn (DocumentFolder $c): array => $this->node($c))->values()->all()
                : [],
        ];
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveFolder(Matter $matter, string $raw): DocumentFolder
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        return DocumentFolderService::resolveInMatter($matter, $raw);
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
