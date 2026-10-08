<?php

namespace App\Services;

use App\Models\DocumentBlob;
use Psr\Http\Message\StreamInterface;

/**
 * Storage abstraction (007, 007-D01, contract §Services). ALL file I/O
 * goes through this interface — controllers and jobs never touch the
 * Storage facade or raw paths directly (architecture door).
 *
 * Implementations: LocalDocumentStore (local disk; S3-compatible path
 * designed, not deployed per 007-D01).
 */
interface DocumentStore
{
    /**
     * Store bytes and return the blob record. Dedupe happens inside: if
     * the SHA-256 of $contents already exists, the existing row is
     * returned with refcount incremented — no new row, no new file.
     *
     * $meta supports:
     *  - 'mime' (string): explicit MIME override; without it the MIME is
     *    sniffed from the bytes and never trusted from an extension
     *    (DOC-01).
     *  - 'quarantined' (bool): write straight to the quarantine prefix
     *    (DOC-03).
     *
     * @param  array{mime?: string, quarantined?: bool}  $meta
     */
    public function put(string $contents, array $meta = []): DocumentBlob;

    /**
     * Open a read stream for the blob. Storage paths are never exposed
     * to callers.
     *
     * @throws DocumentStoreException when the bytes are missing.
     */
    public function get(DocumentBlob $blob): StreamInterface;

    /**
     * Remove the blob's bytes and row. Only allowed when refcount is 0 —
     * otherwise throws, so a live version can never lose its bytes.
     *
     * @throws DocumentStoreException when refcount > 0.
     */
    public function delete(DocumentBlob $blob): void;

    /**
     * Move the blob's bytes to the inaccessible quarantine prefix and
     * flag the row. Quarantined bytes are never served by get().
     */
    public function quarantine(DocumentBlob $blob): void;

    /**
     * Move the blob's bytes to the cold-storage (archive) prefix and
     * flag the row (007 T-10; the physical move lived in
     * RetentionService::archiveBlob before the freeze). Archived bytes
     * remain retrievable through get(); preview is disabled for
     * archived blobs at the controller layer.
     *
     * Idempotent: an already-archived blob is a no-op.
     *
     * @throws DocumentStoreException when the move fails.
     */
    public function archive(DocumentBlob $blob): void;
}
