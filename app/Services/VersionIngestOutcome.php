<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;

/**
 * Outcome of DocumentIngestService::ingestNewVersion() (007 T-07).
 *
 * - created:     a new immutable version N+1 was appended.
 * - duplicate:   byte-identical to the current version — no-op, no new
 *                version; the caller surfaces a notice to the user.
 * - quarantined: the bytes were infected — a 'failed' version was
 *                appended and the blob quarantined (mirrors T-02's v1
 *                quarantine path).
 */
final class VersionIngestOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly Document $document,
        public readonly ?DocumentVersion $version,
    ) {}

    public static function created(Document $document, DocumentVersion $version): self
    {
        return new self('created', $document, $version);
    }

    public static function duplicate(Document $document, DocumentVersion $version): self
    {
        return new self('duplicate', $document, $version);
    }

    public static function quarantined(Document $document, DocumentVersion $version): self
    {
        return new self('quarantined', $document, $version);
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }
}
