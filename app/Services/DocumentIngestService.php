<?php

namespace App\Services;

use App\Mail\DocumentQuarantinedMail;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Upload-time ingest: bytes → blob → malware scan → document + v1
 * (007 T-02, C-01/C-03).
 *
 * The scan runs BEFORE the version row is inserted: document_versions is
 * DB-immutable (007-D06 — the trigger rejects every UPDATE), so the
 * processing_status written at insert must already be final. When the scan
 * engine is unreachable the version is honestly inserted as `scanning`
 * (never silently clean); the stale-scan sweeper retries and raises the
 * ops alert after the configured threshold.
 *
 * MIME is sniffed from the bytes (never trusted from the extension,
 * DOC-01) and checked against the allowlist — unless the caller passes an
 * explicit 'mime' override, which is reserved for trusted internal callers
 * (the fixture loader); the HTTP surface never passes it.
 */
class DocumentIngestService
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly MalwareScanner $scanner,
        private readonly MetadataExtractor $extractor,
        private readonly MimeSniffer $sniffer,
    ) {}

    /**
     * Ingest fully-assembled bytes as a new document (v1).
     *
     * @param  array{title?: string, description?: ?string, tags?: list<string>, folder_id?: ?string, kind?: string, mime?: string, filename?: string, chunked?: bool}  $meta
     *
     * @throws DocumentUploadException
     */
    public function ingest(Matter $matter, User $actor, string $bytes, array $meta = []): Document
    {
        $size = strlen($bytes);
        $mime = $meta['mime'] ?? $this->sniffMime($bytes);

        if (! isset($meta['mime']) && ! $this->mimeAllowed($mime)) {
            throw new DocumentUploadException(
                'mime_not_allowed',
                422,
                "MIME type {$mime} is not accepted for upload."
            );
        }

        $kind = $meta['kind'] ?? 'uploaded';

        if (! in_array($kind, Document::KINDS, true)) {
            throw new DocumentUploadException('invalid_kind', 422, "Unknown document kind {$kind}.");
        }

        $blob = $this->store->put($bytes, ['mime' => $mime]);

        $scan = $this->scanner->scanBytes($bytes);

        $versionStatus = 'ready';
        $documentStatus = 'ready';

        if ($scan->status === ScanStatus::INFECTED) {
            $this->store->quarantine($blob);
            $versionStatus = 'failed';
            $documentStatus = 'quarantined';
        } elseif ($scan->status === ScanStatus::UNAVAILABLE) {
            $versionStatus = 'scanning';
            $documentStatus = 'processing';
        }

        // T-03 (DOC-04): technical metadata extraction. Never throws;
        // failure yields metadata_status 'partial' and the document
        // stays usable. Runs before the version insert because
        // document_versions is DB-immutable (007-D06): page_count must
        // be final at insert time.
        $extraction = $this->extractor->extractBytes($bytes, $mime);

        $document = DB::transaction(function () use (
            $matter,
            $actor,
            $blob,
            $meta,
            $mime,
            $size,
            $kind,
            $versionStatus,
            $documentStatus,
            $scan,
            $extraction,
        ): Document {
            $document = Document::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'folder_id' => $meta['folder_id'] ?? null,
                'title' => $meta['title'] ?? $meta['filename'] ?? 'Untitled upload',
                'description' => $meta['description'] ?? null,
                'tags' => $meta['tags'] ?? [],
                'kind' => $kind,
                'status' => $documentStatus,
                'metadata_status' => $extraction->status,
                'metadata' => $extraction->toArray(),
                'needs_ocr' => $extraction->needsOcr,
                'created_by' => $actor->getKey(),
            ]);

            $version = $document->versions()->create([
                'version_number' => 1,
                'blob_id' => $blob->getKey(),
                'processing_status' => $versionStatus,
                'page_count' => $extraction->pageCount,
                'original_filename' => $meta['filename'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $document->update(['current_version_id' => $version->getKey()]);

            // Audit payload keys must avoid the privileged blocklist
            // (AuditLogger: password/secret/token/hash/recovery) — no
            // digests or tokens are logged, only row ids.
            $basePayload = [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'blob_id' => (string) $blob->getKey(),
                'size' => $size,
                'mime' => $mime,
                'chunked' => (bool) ($meta['chunked'] ?? false),
            ];

            AuditLogger::log('document.uploaded', $actor, $basePayload + [
                'filename' => $meta['filename'] ?? null,
            ], $matter);

            if ($scan->status === ScanStatus::INFECTED) {
                AuditLogger::log('document.quarantined', $actor, $basePayload + [
                    'signature' => $scan->signature,
                ], $matter);

                $this->notifyQuarantine($matter, $actor, $document, $scan->signature);
            } elseif ($scan->status === ScanStatus::CLEAN) {
                AuditLogger::log('document.scanned', $actor, $basePayload + [
                    'verdict' => 'clean',
                ], $matter);
            }

            return $document;
        });

        return $document->fresh() ?? $document;
    }

    /**
     * "Upload new version" entry point (007 T-07, DOC-19/C-07): run the
     * full ingest pipeline (MIME sniff, allowlist, ClamAV scan, metadata
     * extraction) and append version N+1 through DocumentVersioningService.
     * Byte-identical uploads are a no-op (no new version); infected bytes
     * quarantine like v1 ingest.
     *
     * The versioning service is resolved via the container (rather than
     * constructor injection) so this method stays a clean, self-contained
     * addition.
     *
     * @param  array{change_note?: ?string, filename?: ?string}  $meta
     *
     * @throws DocumentUploadException
     */
    public function ingestNewVersion(
        Document $document,
        Matter $matter,
        User $actor,
        string $bytes,
        array $meta = [],
    ): VersionIngestOutcome {
        $mime = $this->sniffMime($bytes);

        if (! $this->mimeAllowed($mime)) {
            throw new DocumentUploadException(
                'mime_not_allowed',
                422,
                "MIME type {$mime} is not accepted for upload."
            );
        }

        $sha256 = hash('sha256', $bytes);

        $current = $document->currentVersion()->first();
        $currentSha = $current?->blob()->first()?->sha256;

        if ($current !== null && $currentSha !== null && hash_equals($currentSha, $sha256)) {
            // Byte-identical upload → no-op with notice (DOC-18/C-10): no
            // new version, no audit row, no pipeline work.
            return VersionIngestOutcome::duplicate($document, $current);
        }

        $scan = $this->scanner->scanBytes($bytes);

        $versioning = app(DocumentVersioningService::class);

        if ($scan->status === ScanStatus::INFECTED) {
            $version = $versioning->createVersion($document, $actor, $bytes, [
                'change_note' => $meta['change_note'] ?? null,
                'processing_status' => 'failed',
                'original_filename' => $meta['filename'] ?? null,
                'mime' => $mime,
            ], $matter);

            $blob = $version->blob()->firstOrFail();
            $this->store->quarantine($blob);
            $document->update(['status' => 'quarantined']);

            AuditLogger::log('document.quarantined', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'size' => strlen($bytes),
                'mime' => $mime,
                'signature' => $scan->signature,
            ], $matter);

            $this->notifyQuarantine($matter, $actor, $document, $scan->signature);

            return VersionIngestOutcome::quarantined($document->fresh() ?? $document, $version);
        }

        // T-03 (DOC-04): technical metadata extraction. Never throws;
        // failure yields metadata_status 'partial' and the document stays
        // usable. Runs before the version insert because
        // document_versions is DB-immutable (007-D06).
        $extraction = $this->extractor->extractBytes($bytes, $mime);

        $version = $versioning->createVersion($document, $actor, $bytes, [
            'change_note' => $meta['change_note'] ?? null,
            'processing_status' => $scan->status === ScanStatus::UNAVAILABLE ? 'scanning' : 'ready',
            'page_count' => $extraction->pageCount,
            'original_filename' => $meta['filename'] ?? null,
            'mime' => $mime,
        ], $matter);

        if ($version->processing_status === 'scanning') {
            $document->update(['status' => 'processing']);
        }

        if ($scan->status === ScanStatus::CLEAN) {
            AuditLogger::log('document.scanned', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'size' => strlen($bytes),
                'mime' => $mime,
                'verdict' => 'clean',
            ], $matter);
        }

        return VersionIngestOutcome::created($document->fresh() ?? $document, $version);
    }

    /**
     * Byte-identical dedupe at the document level: the existing document in
     * this matter whose current version holds these exact bytes, or null.
     * Callers turn a hit into a no-op-with-notice (no new row, no new
     * version); the blob-level dedupe inside DocumentStore still collapses
     * cross-matter duplicates to one blob row.
     */
    public function findDuplicateInMatter(Matter $matter, string $sha256): ?Document
    {
        /** @var Document|null $found */
        $found = Document::query()
            ->where('documents.matter_id', $matter->getKey())
            ->whereNull('documents.deleted_at')
            ->join('document_versions as v', 'v.id', '=', 'documents.current_version_id')
            ->join('document_blobs as b', 'b.id', '=', 'v.blob_id')
            ->where('b.sha256', $sha256)
            ->select('documents.*')
            ->first();

        return $found;
    }

    /**
     * Queue the quarantine alert to the matter org's admins (C-03). The
     * mail carries metadata only — never bytes, paths, or hashes.
     */
    public function notifyQuarantine(Matter $matter, ?User $actor, Document $document, ?string $signature): void
    {
        $orgId = (string) $matter->org_id;

        $admins = User::query()
            ->where('org_id', $orgId)
            ->whereHas('roles', static function (Builder $q) use ($orgId): void {
                $q->where('roles.name', 'org_admin')->where('roles.org_id', $orgId);
            })
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $orgName = (string) $matter->organization->name;

        Mail::to($admins)->queue(new DocumentQuarantinedMail(
            orgName: (string) $orgName,
            documentTitle: (string) $document->title,
            matterNumber: (string) $matter->matter_number,
            uploaderName: $actor !== null ? (string) $actor->name : 'Vision Law',
            signature: $signature ?? 'unknown',
            quarantinedAt: now()->toIso8601String(),
        ));
    }

    private function sniffMime(string $bytes): string
    {
        // Container-aware sniffing (T-03): plain finfo reports OOXML as
        // application/zip and legacy Office as x-ole-storage, neither of
        // which is on the allowlist. The client extension is never used.
        return $this->sniffer->sniff($bytes);
    }

    private function mimeAllowed(string $mime): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('document.allowed_mimes', []);

        return in_array($mime, $allowed, true);
    }
}
