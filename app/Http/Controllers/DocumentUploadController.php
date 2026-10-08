<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Matter;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChunkedUploadService;
use App\Services\DocumentIngestService;
use App\Services\DocumentUploadException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Upload pipeline endpoints (spec 007 T-02; 03-contract.md §Upload).
 *
 * - POST   /matters/{matter}/documents/upload                     → store (single-shot, ≤100 MiB)
 * - POST   /matters/{matter}/documents/uploads/init               → init (chunked session)
 * - GET    /matters/{matter}/documents/uploads/{session}           → show (resume bitmap)
 * - PUT    /matters/{matter}/documents/uploads/{session}/chunks/{n} → chunk
 * - POST   /matters/{matter}/documents/uploads/{session}/complete → complete
 * - DELETE /matters/{matter}/documents/uploads/{session}           → cancel
 *
 * All routes carry `matter.access:edit`; chunk-session routes additionally
 * require session ownership (403 + `document.access.denied` audit
 * otherwise). The matter is read from the request attributes — resolved by
 * the middleware before any data access, never re-resolved here.
 *
 * The GET show route is a small addition to the contract's table: resume
 * needs the received-chunk bitmap, and there is no other way to read it.
 */
class DocumentUploadController extends Controller
{
    /**
     * Single-shot upload (C-01): stream bytes with a running SHA-256,
     * reject >100 MiB with 413 before anything is stored, sniff MIME from
     * the bytes, dedupe identical bytes.
     */
    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{file: UploadedFile, folder_id?: ?string, title?: string, description?: ?string, tags?: list<string>} $validated */
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'folder_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        if (! $file->isValid()) {
            return response()->json(['code' => 'upload_invalid'], 422);
        }

        $max = (int) config('document.max_single_upload_bytes');
        $declaredSize = $file->getSize();

        // 413 before storage: the declared size is checked first, then the
        // cap is re-enforced while streaming in case it lies.
        if ($declaredSize !== false && $declaredSize > $max) {
            return response()->json(['code' => 'upload_too_large', 'max_bytes' => $max], 413);
        }

        try {
            $folderId = $this->resolveFolder($matter, $validated['folder_id'] ?? null);
            $bytes = $this->streamBytes($file, $max);
        } catch (DocumentUploadException $e) {
            return $this->rejection($e);
        }

        $digest = hash('sha256', $bytes);
        $ingest = app(DocumentIngestService::class);

        $existing = $ingest->findDuplicateInMatter($matter, $digest);

        if ($existing !== null) {
            return response()->json([
                'data' => $this->documentResource($existing),
                'notice' => 'identical_bytes_already_stored',
            ], 200);
        }

        try {
            $document = $ingest->ingest($matter, $actor, $bytes, [
                'title' => $validated['title'] ?? $file->getClientOriginalName(),
                'description' => $validated['description'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'folder_id' => $folderId,
                'filename' => $file->getClientOriginalName(),
            ]);
        } catch (DocumentUploadException $e) {
            return $this->rejection($e);
        }

        return response()->json(['data' => $this->documentResource($document)], 201);
    }

    /**
     * Initiate a chunked upload session (C-02, 007-D05).
     */
    public function init(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{filename: string, size: int, sha256: string, mime_hint?: ?string} $validated */
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:500'],
            'size' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'mime_hint' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $session = app(ChunkedUploadService::class)->initiate($matter, $actor, $validated);
        } catch (DocumentUploadException $e) {
            return $this->rejection($e);
        }

        return response()->json(['data' => $this->sessionResource($session)], 201);
    }

    /**
     * Read a session: status plus the received-chunk bitmap for resume.
     */
    public function show(Request $request, string $matter, string $session): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $sessionModel = $this->resolveSession($matterModel, $actor, $session);

        return response()->json(['data' => $this->sessionResource($sessionModel)]);
    }

    /**
     * Idempotent per-chunk PUT (C-02). Raw bytes in the request body.
     */
    public function chunk(Request $request, string $matter, string $session, string $n): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $sessionModel = $this->resolveSession($matterModel, $actor, $session);

        try {
            $outcome = app(ChunkedUploadService::class)->putChunk(
                $sessionModel,
                (int) $n,
                $request->getContent()
            );
        } catch (DocumentUploadException $e) {
            return $this->rejection($e);
        }

        return response()->json([
            'data' => [
                'received_chunks' => app(ChunkedUploadService::class)->bitmap($sessionModel->fresh() ?? $sessionModel),
                'deduped' => $outcome === 'duplicate',
            ],
        ], 200);
    }

    /**
     * Complete a session: verify total SHA-256, ingest, garbage-collect
     * chunks. Mismatch → 422 `hash_mismatch`, session and chunks retained.
     */
    public function complete(Request $request, string $matter, string $session): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $sessionModel = $this->resolveSession($matterModel, $actor, $session);

        /** @var array{folder_id?: ?string, title?: string, description?: ?string, tags?: list<string>} $validated */
        $validated = $request->validate([
            'folder_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        try {
            $folderId = $this->resolveFolder($matterModel, $validated['folder_id'] ?? null);

            $document = app(ChunkedUploadService::class)->complete($sessionModel, $actor, [
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'folder_id' => $folderId,
            ]);
        } catch (DocumentUploadException $e) {
            return $this->rejection($e);
        }

        if ($document->getAttribute('duplicate_of_existing') === true) {
            return response()->json([
                'data' => $this->documentResource($document),
                'notice' => 'identical_bytes_already_stored',
            ], 200);
        }

        return response()->json(['data' => $this->documentResource($document)], 201);
    }

    /**
     * Cancel a session: chunks garbage-collected immediately.
     */
    public function cancel(Request $request, string $matter, string $session): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $sessionModel = $this->resolveSession($matterModel, $actor, $session);

        app(ChunkedUploadService::class)->cancel($sessionModel, $actor);

        return response()->json(['data' => ['cancelled' => true]], 200);
    }

    /**
     * Stream the uploaded file with a running SHA-256 and a hard size cap.
     * The cap is enforced during the read so an over-limit body never
     * reaches the store (413 before storage, C-01).
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

        $hash = hash_init('sha256');
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

                hash_update($hash, $buf);
                $bytes .= $buf;
            }
        } finally {
            fclose($handle);
        }

        return $bytes;
    }

    /**
     * Resolve the session within this matter and enforce ownership.
     * Unknown session → 404 (no existence leak); another user's session →
     * 403 + `document.access.denied` audit.
     */
    private function resolveSession(Matter $matter, User $actor, string $raw): UploadSession
    {
        if (! Str::isUuid($raw)) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        $session = UploadSession::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->first();

        if ($session === null) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        if ((string) $session->created_by !== (string) $actor->getKey()) {
            AuditLogger::log('document.access.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'session_id' => (string) $session->getKey(),
                'attempted_action' => 'upload_session',
            ], $matter, explicitOrgId: (string) $actor->org_id);

            abort(response()->json(['code' => 'forbidden'], 403));
        }

        return $session;
    }

    /**
     * @throws DocumentUploadException
     */
    private function resolveFolder(Matter $matter, ?string $folderId): ?string
    {
        if ($folderId === null) {
            return null;
        }

        $folder = DocumentFolder::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($folderId)
            ->first();

        if ($folder === null) {
            throw new DocumentUploadException('folder_not_in_matter', 422, 'Folder does not belong to this matter.');
        }

        return (string) $folder->getKey();
    }

    private function rejection(DocumentUploadException $e): JsonResponse
    {
        return response()->json(['code' => $e->errorCode], $e->httpStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionResource(UploadSession $session): array
    {
        return [
            'id' => (string) $session->getKey(),
            'filename' => $session->filename,
            'size' => $session->size,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->totalChunks(),
            'received_chunks' => app(ChunkedUploadService::class)->bitmap($session),
            'status' => $session->status,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    /**
     * No raw blobs, paths, tokens, or hashes reach the response
     * (contract §Component props).
     *
     * @return array<string, mixed>
     */
    private function documentResource(Document $document): array
    {
        $document->loadMissing(['currentVersion.blob']);
        $version = $document->currentVersion;

        return [
            'id' => (string) $document->getKey(),
            'matter_id' => (string) $document->matter_id,
            'title' => $document->title,
            'kind' => $document->kind,
            'status' => $document->status,
            'folder_id' => $document->folder_id !== null ? (string) $document->folder_id : null,
            'version' => $version === null ? null : [
                'id' => (string) $version->getKey(),
                'version_number' => $version->version_number,
                'processing_status' => $version->processing_status,
                'size' => $version->blob?->size,
                'mime' => $version->blob?->mime_sniffed,
            ],
            'created_at' => $document->created_at?->toIso8601String(),
        ];
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
