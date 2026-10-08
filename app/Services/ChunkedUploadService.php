<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Matter;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked/resumable upload sessions (007-D05, C-02).
 *
 * Protocol: initiate → idempotent per-chunk PUT (8 MiB chunks) → resume via
 * the received-chunks bitmap → complete with total SHA-256 verification
 * (422 + session retained on mismatch) → cancel with garbage collection.
 * Sessions expire after 24h idle; the sweeper garbage-collects them.
 *
 * Chunk bytes are staging area, not content-addressed blobs: they live
 * under the configured staging prefix on the documents disk and are
 * deleted on complete/cancel/expiry. Only DocumentStore touches blob
 * bytes (architecture door) — this service never reads blob storage.
 */
class ChunkedUploadService
{
    public function __construct(
        private readonly DocumentIngestService $ingest,
    ) {}

    /**
     * @param  array{filename: string, size: int, sha256: string, mime_hint?: ?string, title?: string, description?: ?string, tags?: list<string>, folder_id?: ?string}  $data
     *
     * @throws DocumentUploadException
     */
    public function initiate(Matter $matter, User $actor, array $data): UploadSession
    {
        $singleMax = (int) config('document.max_single_upload_bytes');
        $chunkedMax = (int) config('document.max_chunked_upload_bytes');

        if ($data['size'] <= $singleMax || $data['size'] > $chunkedMax) {
            throw new DocumentUploadException(
                'invalid_upload_size',
                422,
                'Chunked uploads must be larger than '.number_format($singleMax).' bytes and at most '.number_format($chunkedMax).' bytes.'
            );
        }

        return DB::transaction(function () use ($matter, $actor, $data): UploadSession {
            $session = UploadSession::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'created_by' => $actor->getKey(),
                'filename' => $data['filename'],
                'size' => $data['size'],
                'expected_sha256' => strtolower($data['sha256']),
                'mime_hint' => $data['mime_hint'] ?? null,
                'chunk_size' => (int) config('document.chunk_size_bytes'),
                'received_chunks' => [],
                'status' => 'active',
                'expires_at' => now()->addHours((int) config('document.upload_session_ttl_hours', 24)),
            ]);

            AuditLogger::log('document.upload.initiated', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'session_id' => (string) $session->getKey(),
                'filename' => $data['filename'],
                'size' => $data['size'],
                'chunk_size' => $session->chunk_size,
            ], $matter);

            return $session;
        });
    }

    /**
     * Store one chunk. Idempotent per (session, index): re-PUT of identical
     * bytes is a 200 no-op; differing bytes are a 409 conflict.
     *
     * @return 'stored'|'duplicate'
     *
     * @throws DocumentUploadException
     */
    public function putChunk(UploadSession $session, int $index, string $bytes): string
    {
        $this->assertUsable($session);

        $total = $session->totalChunks();

        if ($index < 0 || $index >= $total) {
            throw new DocumentUploadException('invalid_chunk_index', 422, "Chunk index {$index} out of range (0–".($total - 1).').');
        }

        $expected = $index === $total - 1
            ? $session->size - ($index * $session->chunk_size)
            : $session->chunk_size;

        if (strlen($bytes) !== $expected) {
            throw new DocumentUploadException(
                'chunk_size_mismatch',
                422,
                "Chunk {$index} must be exactly {$expected} bytes."
            );
        }

        $path = $this->chunkPath($session, $index);

        if ($this->disk()->exists($path)) {
            /** @var string $existing */
            $existing = $this->disk()->get($path);

            if (hash_equals(hash('sha256', $existing), hash('sha256', $bytes))) {
                return 'duplicate';
            }

            throw new DocumentUploadException('chunk_conflict', 409, "Chunk {$index} was already received with different bytes.");
        }

        $this->disk()->put($path, $bytes);

        $received = $session->received_chunks ?? [];
        $received[] = $index;
        $received = array_values(array_unique($received));
        sort($received);

        $session->update([
            'received_chunks' => $received,
            // Every chunk resets the 24h idle clock (007-D05).
            'expires_at' => now()->addHours((int) config('document.upload_session_ttl_hours', 24)),
        ]);

        return 'stored';
    }

    /**
     * The resume bitmap: sorted indexes of received chunks.
     *
     * @return list<int>
     */
    public function bitmap(UploadSession $session): array
    {
        $received = $session->received_chunks ?? [];
        sort($received);

        return $received;
    }

    /**
     * Verify total SHA-256 and ingest. Mismatch → 422 `hash_mismatch` with
     * the session and its chunks retained for retry. Byte-identical
     * re-upload of bytes already in this matter → the existing document
     * (flagged via the `duplicate_of_existing` attribute), no new row.
     *
     * @param  array{title?: string, description?: ?string, tags?: list<string>, folder_id?: ?string}  $meta
     *
     * @throws DocumentUploadException
     */
    public function complete(UploadSession $session, User $actor, array $meta = []): Document
    {
        $this->assertUsable($session);

        $total = $session->totalChunks();
        $received = $this->bitmap($session);
        $missing = array_values(array_diff(range(0, $total - 1), $received));

        if ($missing !== []) {
            throw new DocumentUploadException(
                'incomplete_upload',
                422,
                'Missing chunks: '.implode(',', $missing).'.'
            );
        }

        $matter = $session->matter;

        if ($matter === null) {
            throw new DocumentUploadException('session_not_active', 422, 'Upload session has no matter.');
        }

        // Stream chunks in order: running SHA-256 plus reassembly. The
        // assembled bytes stay in memory only here; chunk files are the
        // durable copy until ingest succeeds.
        $hash = hash_init('sha256');
        $assembled = '';

        for ($i = 0; $i < $total; $i++) {
            /** @var string $chunk */
            $chunk = $this->disk()->get($this->chunkPath($session, $i));
            hash_update($hash, $chunk);
            $assembled .= $chunk;
            unset($chunk);
        }

        $digest = hash_final($hash);

        if (! hash_equals($session->expected_sha256, strtolower($digest))) {
            AuditLogger::log('document.upload.hash_mismatch', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'session_id' => (string) $session->getKey(),
                'chunks_received' => count($received),
            ], $matter);

            throw new DocumentUploadException(
                'hash_mismatch',
                422,
                'Assembled bytes do not match the expected SHA-256. The session and its chunks are retained for retry.'
            );
        }

        $existing = $this->ingest->findDuplicateInMatter($matter, strtolower($digest));

        if ($existing !== null) {
            $this->markCompleted($session);
            $this->deleteChunks($session);
            $existing->setAttribute('duplicate_of_existing', true);

            return $existing;
        }

        $document = $this->ingest->ingest($matter, $actor, $assembled, [
            'title' => $meta['title'] ?? $session->filename,
            'description' => $meta['description'] ?? null,
            'tags' => $meta['tags'] ?? [],
            'folder_id' => $meta['folder_id'] ?? null,
            'filename' => $session->filename,
            'chunked' => true,
        ]);

        unset($assembled);

        $this->markCompleted($session);
        $this->deleteChunks($session);

        return $document;
    }

    /**
     * Cancel a session: chunk bytes are garbage-collected immediately; the
     * row is retained as `cancelled` for the audit trail.
     */
    public function cancel(UploadSession $session, User $actor): void
    {
        $this->deleteChunks($session);
        $session->update(['status' => 'cancelled']);

        $matter = $session->matter;

        if ($matter !== null) {
            AuditLogger::log('document.upload.cancelled', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'session_id' => (string) $session->getKey(),
                'filename' => $session->filename,
            ], $matter);
        }
    }

    /**
     * Garbage-collect idle sessions (24h without chunk activity). Returns
     * the number of sessions expired.
     */
    public function sweepExpired(): int
    {
        $expired = UploadSession::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $session) {
            $this->deleteChunks($session);
            $session->update(['status' => 'expired']);
        }

        return $expired->count();
    }

    /**
     * @throws DocumentUploadException
     */
    private function assertUsable(UploadSession $session): void
    {
        if ($session->status !== 'active') {
            throw new DocumentUploadException('session_not_active', 422, 'Upload session is '.$session->status.'.');
        }

        if ($session->isExpired()) {
            $this->deleteChunks($session);
            $session->update(['status' => 'expired']);

            throw new DocumentUploadException('session_expired', 410, 'Upload session expired after 24h idle.');
        }
    }

    private function markCompleted(UploadSession $session): void
    {
        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    private function sessionPrefix(UploadSession $session): string
    {
        return trim((string) config('document.staging_prefix', 'staging'), '/').'/'.(string) $session->getKey();
    }

    private function chunkPath(UploadSession $session, int $index): string
    {
        return $this->sessionPrefix($session).'/'.$index;
    }

    private function deleteChunks(UploadSession $session): void
    {
        $this->disk()->deleteDirectory($this->sessionPrefix($session));
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('document.disk', 'local'));
    }
}
