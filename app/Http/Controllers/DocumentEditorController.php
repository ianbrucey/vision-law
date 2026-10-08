<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthoredDocumentService;
use App\Services\DocumentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Built-in document editor (spec 007 T-04, DOC-09/10, 007-D03).
 *
 * The editor is a typing surface: headings, lists, tables, page-break
 * hints, a pleading line-numbering toggle — plain contenteditable, no
 * CDN libraries, x-ui.* components only. NO drafting intelligence.
 *
 * - Autosave drafts POST to `documents.draft.update` every 30s: ephemeral
 *   draft state, NOT a version, no audit row (contract §Editor).
 * - Explicit Save publishes a new immutable version as structured HTML
 *   plus a generated PDF rendition.
 * - Content edits on authored/generated docs = new versions; metadata
 *   edits (title/description/tags/folder/matter) never create versions
 *   (that is T-05's documents.update — this controller never versions
 *   on metadata writes). Uploaded binaries get 422 here: they version via
 *   "upload new version", not in-app editing.
 */
class DocumentEditorController extends Controller
{
    public function __construct(
        private readonly AuthoredDocumentService $authored,
        private readonly DocumentStore $store,
    ) {}

    /**
     * New authored document: title/metadata form before the typing
     * surface opens.
     */
    public function create(Request $request): View
    {
        $matter = $this->authorizedMatter($request);

        return view('documents.editor', [
            'matter' => $matter,
            'document' => null,
            'content' => '',
            'isNew' => true,
        ]);
    }

    /**
     * Create the authored document shell (no version yet — content comes
     * from the editor). Audit: document.created (contract §Documents).
     */
    public function store(Request $request): RedirectResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{title: string, description?: string|null, folder_id?: string|null, tags?: list<string>|null} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'folder_id' => ['nullable', 'uuid', Rule::exists('document_folders', 'id')->where('matter_id', $matter->getKey())],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        $document = Document::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'folder_id' => $validated['folder_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'kind' => 'authored',
            'status' => 'processing',
            'created_by' => $actor->getKey(),
        ]);

        AuditLogger::log('document.created', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matter->getKey(),
            'document_id' => (string) $document->getKey(),
            'title' => $document->title,
            'kind' => 'authored',
        ], $matter);

        return redirect()
            ->route('documents.editor.edit', [$matter, $document])
            ->with('toast', ['tone' => 'ok', 'message' => 'Document created — write, then Save to publish v1.']);
    }

    /**
     * The editor surface for an authored/generated document.
     */
    public function edit(Request $request, string $matter, string $document): View
    {
        $matterModel = $this->authorizedMatter($request);
        $documentModel = $this->resolveAuthoredDocument($matterModel, $document);

        return view('documents.editor', [
            'matter' => $matterModel,
            'document' => $documentModel,
            'content' => $this->editorContent($documentModel),
            'isNew' => false,
        ]);
    }

    /**
     * Autosave draft (every 30s). Ephemeral: no version, no audit row.
     * The draft is discarded on publish and resumes editing on reopen.
     */
    public function draft(Request $request, string $matter, string $document): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $documentModel = $this->resolveAuthoredDocument($matterModel, $document);

        /** @var array{content: string} $validated */
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10485760'],
        ]);

        $savedAt = now();
        $documentModel->update([
            'draft_content' => $validated['content'],
            'draft_updated_at' => $savedAt,
        ]);

        return response()->json([
            'ok' => true,
            'draft_updated_at' => $savedAt->toIso8601String(),
        ]);
    }

    /**
     * Explicit Save: publish the editor content as a new immutable
     * version (structured HTML + generated PDF rendition).
     * Audit: document.version.created.
     */
    public function publish(Request $request, string $matter, string $document): RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $documentModel = $this->resolveAuthoredDocument($matterModel, $document);

        /** @var array{content: string, change_note?: string|null} $validated */
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10485760'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ]);

        $version = $this->authored->publishVersion(
            $documentModel,
            $validated['content'],
            $validated['change_note'] ?? null,
            $actor,
        );

        AuditLogger::log('document.version.created', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matterModel->getKey(),
            'document_id' => (string) $documentModel->getKey(),
            'version_id' => (string) $version->getKey(),
            'version_number' => $version->version_number,
        ], $matterModel);

        return redirect()
            ->route('documents.editor.edit', [$matterModel, $documentModel])
            ->with('toast', ['tone' => 'ok', 'message' => "Saved as v{$version->version_number} — HTML + PDF rendition."]);
    }

    /**
     * Editor content: unsaved draft wins, otherwise the current
     * version's HTML. Uploaded binaries never reach here.
     */
    private function editorContent(Document $document): string
    {
        if ($document->draft_content !== null && $document->draft_content !== '') {
            return $document->draft_content;
        }

        $version = $document->currentVersion;

        if ($version === null) {
            return '';
        }

        return (string) $this->store->get($version->blob)->getContents();
    }

    /**
     * Resolve a document that the editor may touch: scoped to the
     * matter (404 otherwise — no existence leak) and restricted to
     * authored/generated kinds. Uploaded binaries are 422: they offer
     * "upload new version" instead of in-app editing (C-07).
     *
     * @throws AccessDeniedException 404 when the document is not in this matter
     */
    private function resolveAuthoredDocument(Matter $matter, string $id): Document
    {
        if (! Str::isUuid($id)) {
            throw new AccessDeniedException(404);
        }

        $document = Document::query()
            ->whereKey($id)
            ->where('matter_id', $matter->getKey())
            ->first();

        if ($document === null) {
            throw new AccessDeniedException(404);
        }

        if (! in_array($document->kind, ['authored', 'generated'], true)) {
            abort(response()->json([
                'code' => 'not_editable',
                'message' => 'Uploaded documents cannot be edited in-app — upload a new version instead.',
            ], 422));
        }

        return $document;
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
