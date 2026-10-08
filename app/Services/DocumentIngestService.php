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
                'created_by' => $actor->getKey(),
            ]);

            $version = $document->versions()->create([
                'version_number' => 1,
                'blob_id' => $blob->getKey(),
                'processing_status' => $versionStatus,
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
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    private function mimeAllowed(string $mime): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('document.allowed_mimes', []);

        return in_array($mime, $allowed, true);
    }
}
