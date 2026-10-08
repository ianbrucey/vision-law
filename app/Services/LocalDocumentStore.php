<?php

namespace App\Services;

use App\Models\DocumentBlob;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/**
 * Local-disk DocumentStore (007-D01). Blobs live under the configured
 * disk, sharded by SHA-256 prefix so no directory grows unbounded:
 * `<base>/ab/cd/<sha256>`. Quarantined bytes move to the configured
 * quarantine prefix and are never served back.
 *
 * Dedupe: the sha256 column is UNIQUE. On a duplicate put the existing
 * row is returned with refcount incremented inside a transaction, so
 * identical bytes always collapse to one row.
 */
class LocalDocumentStore implements DocumentStore
{
    public function __construct(
        private readonly MimeSniffer $sniffer,
    ) {}

    public function put(string $contents, array $meta = []): DocumentBlob
    {
        $sha256 = hash('sha256', $contents);
        $size = strlen($contents);
        $mime = $meta['mime'] ?? $this->sniffMime($contents);
        $quarantined = (bool) ($meta['quarantined'] ?? false);

        return DB::transaction(function () use ($sha256, $size, $mime, $contents, $quarantined) {
            $existing = DocumentBlob::where('sha256', $sha256)->lockForUpdate()->first();

            if ($existing !== null) {
                $existing->increment('refcount');

                // A quarantined re-put of a known-clean blob keeps the
                // clean row untouched; callers quarantine explicitly.
                return $existing->refresh();
            }

            $path = $this->blobPath($sha256, $quarantined);

            try {
                $this->disk()->put($path, $contents);
            } catch (\Throwable $e) {
                throw new DocumentStoreException("Failed to write blob {$sha256}: {$e->getMessage()}", 0, $e);
            }

            try {
                return DocumentBlob::create([
                    'sha256' => $sha256,
                    'size' => $size,
                    'mime_sniffed' => $mime,
                    'storage_path' => $path,
                    'refcount' => 1,
                    'quarantined' => $quarantined,
                ]);
            } catch (QueryException $e) {
                // Lost a concurrent race on the UNIQUE sha256: drop our
                // duplicate bytes and fall back to the winner's row.
                $this->disk()->delete($path);
                $winner = DocumentBlob::where('sha256', $sha256)->lockForUpdate()->firstOrFail();
                $winner->increment('refcount');

                return $winner->refresh();
            }
        });
    }

    public function get(DocumentBlob $blob): StreamInterface
    {
        $blob = $blob->fresh() ?? $blob;

        if ($blob->quarantined) {
            throw new DocumentStoreException("Blob {$blob->sha256} is quarantined and cannot be served");
        }

        $fullPath = $this->disk()->path($blob->storage_path);

        if (! is_file($fullPath)) {
            throw new DocumentStoreException("Blob bytes missing for {$blob->sha256} ({$blob->storage_path})");
        }

        $resource = fopen($fullPath, 'rb');
        if ($resource === false) {
            throw new DocumentStoreException("Could not open blob bytes for {$blob->sha256}");
        }

        return Utils::streamFor($resource);
    }

    public function delete(DocumentBlob $blob): void
    {
        $blob = $blob->fresh() ?? $blob;

        if ($blob->refcount > 0) {
            throw new DocumentStoreException(
                "Cannot delete blob {$blob->sha256}: refcount {$blob->refcount} > 0"
            );
        }

        DB::transaction(function () use ($blob) {
            $this->disk()->delete($blob->storage_path);
            $blob->delete();
        });
    }

    public function quarantine(DocumentBlob $blob): void
    {
        $blob = $blob->fresh() ?? $blob;

        if ($blob->quarantined) {
            return;
        }

        $quarantinePath = $this->blobPath($blob->sha256, true);

        DB::transaction(function () use ($blob, $quarantinePath) {
            if (! $this->disk()->move($blob->storage_path, $quarantinePath)) {
                throw new DocumentStoreException("Failed to quarantine blob {$blob->sha256}");
            }

            $blob->update([
                'storage_path' => $quarantinePath,
                'quarantined' => true,
            ]);
        });
    }

    /**
     * Move one blob's bytes to the cold-storage prefix and flag the row
     * (007 T-10 — moved here from RetentionService::archiveBlob at the
     * freeze; controllers/jobs never touch Storage directly). Idempotent:
     * an already-archived blob is a no-op. Bytes stay retrievable via
     * get(); preview is disabled for archived blobs at the controller
     * layer (DocumentPreviewController::resolvePreviewTarget).
     */
    public function archive(DocumentBlob $blob): void
    {
        $blob = $blob->fresh() ?? $blob;

        if ($blob->archived) {
            return;
        }

        $sha = (string) $blob->sha256;
        $coldPath = trim((string) config('document.cold_prefix', 'cold'), '/')
            .'/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;

        DB::transaction(function () use ($blob, $coldPath, $sha) {
            if (! $this->disk()->move((string) $blob->storage_path, $coldPath)) {
                throw new DocumentStoreException("Failed to archive blob {$sha}");
            }

            $blob->forceFill([
                'storage_path' => $coldPath,
                'archived' => true,
            ])->save();
        });
    }

    /**
     * MIME is sniffed from the bytes (DOC-01) — never trusted from the
     * client-supplied extension. Container-aware (T-03): OOXML/OLE
     * refinement so Office documents land on their real MIME.
     */
    private function sniffMime(string $contents): string
    {
        return $this->sniffer->sniff($contents);
    }

    /**
     * Sharded storage path: ab/cd/<sha256>, optionally under the
     * quarantine prefix. The full path never leaves this class.
     */
    private function blobPath(string $sha256, bool $quarantined): string
    {
        $relative = substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;

        if ($quarantined) {
            return trim((string) config('document.quarantine_prefix', 'quarantine'), '/').'/'.$relative;
        }

        return $relative;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('document.disk', 'local'));
    }
}
