<?php

namespace App\Services;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DocumentFilingException;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Document filing operations (007 T-05, DOC-11/12/13).
 *
 * Filing invariants:
 * - Every document belongs to exactly one matter; move-matter carries the
 *   full version history and the audit trail (history is never rewritten —
 *   existing audit rows keep their original matter_id).
 * - Permissions are re-evaluated against the destination matter at move
 *   time: the mover needs :edit there (03-contract.md).
 * - Soft delete → per-matter trash (30 days) → scheduled hard delete.
 *   Legal hold blocks hard delete with 423 (never silently).
 * - Restore returns every version intact — versions are never deleted by
 *   the trash path.
 * - Bulk move/tag/download-as-ZIP preserve folder structure.
 *
 * Every mutation writes its contract audit event in the same transaction
 * (006-D04 pattern). Denial audits are written BEFORE the exception
 * leaves, so the denial is recorded even though the mutation rolls back.
 */
final class DocumentFilingService
{
    /**
     * Days a trashed document waits before the scheduled purge may hard
     * delete it (DOC-11).
     */
    public const TRASH_RETENTION_DAYS = 30;

    public const BULK_DOWNLOAD_MAX_DOCS = 200;

    /**
     * MIME → extension map for ZIP entry filenames (title + extension).
     *
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/tiff' => 'tiff',
        'message/rfc822' => 'eml',
    ];

    /**
     * Whether an active (unreleased) legal hold blocks destruction of the
     * document — a hold on the document itself or on its matter.
     */
    public static function holdsBlocking(Document $document): bool
    {
        return LegalHold::query()
            ->active()
            ->where('org_id', $document->org_id)
            ->where(function ($query) use ($document): void {
                $query->where('document_id', $document->getKey())
                    ->orWhere('matter_id', $document->matter_id);
            })
            ->exists();
    }

    /**
     * Move a document between folders of the same matter.
     *
     * @throws DocumentFilingException 422 folder_not_in_matter | 409 in_trash
     * @throws ModelNotFoundException when the folder is foreign
     */
    public static function moveToFolder(Matter $matter, Document $document, User $actor, ?string $folderId): Document
    {
        if ($document->trashed()) {
            throw new DocumentFilingException(409, 'in_trash', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        $folder = $folderId !== null
            ? DocumentFolderService::resolveInMatter($matter, $folderId) // 404 when foreign
            : null;

        return DB::transaction(function () use ($matter, $document, $actor, $folder): Document {
            $fromFolderId = $document->folder_id !== null ? (string) $document->folder_id : null;
            $document->folder_id = $folder?->getKey();
            $document->save();

            AuditLogger::log('document.moved', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'from_folder_id' => $fromFolderId,
                'to_folder_id' => $folder !== null ? (string) $folder->getKey() : null,
            ], $matter);

            return $document->refresh();
        });
    }

    /**
     * Re-file a document to another matter (DOC-13).
     *
     * Carries the full version history and audit trail: version rows are
     * keyed to the document (untouched), and existing audit rows keep
     * their original matter_id. Permissions are re-evaluated at move time
     * — the mover needs :edit on the destination matter.
     *
     * @throws DocumentFilingException 422 folder_not_in_matter | 409 in_trash | same_matter
     * @throws AccessDeniedException 403/404 when the destination denies :edit
     * @throws ModelNotFoundException when the folder is foreign
     */
    public static function moveToMatter(Matter $source, Document $document, User $actor, Matter $destination, ?string $folderId = null): Document
    {
        if ($document->trashed()) {
            throw new DocumentFilingException(409, 'in_trash', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        if ((string) $destination->getKey() === (string) $source->getKey()) {
            throw new DocumentFilingException(422, 'same_matter', [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $source->getKey(),
            ]);
        }

        // Re-evaluate permissions against the destination matter at move
        // time (03-contract.md). Cross-org / ungranted destinations deny
        // here — the denial audit names IDs only (leak sentinels).
        try {
            AccessControl::authorize($actor, 'edit', $destination);
        } catch (AccessDeniedException $e) {
            AuditLogger::log('document.access.denied', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $destination->getKey(),
                'attempted_action' => 'move-matter',
            ], $destination);

            throw $e;
        }

        $folder = $folderId !== null
            ? DocumentFolderService::resolveInMatter($destination, $folderId)
            : null;

        return DB::transaction(function () use ($source, $document, $actor, $destination, $folder): Document {
            $document->matter_id = $destination->getKey();
            $document->org_id = $destination->org_id;
            $document->folder_id = $folder?->getKey();
            $document->save();

            $payload = [
                'document_id' => (string) $document->getKey(),
                'from_matter_id' => (string) $source->getKey(),
                'to_matter_id' => (string) $destination->getKey(),
                'to_folder_id' => $folder !== null ? (string) $folder->getKey() : null,
            ];

            AuditLogger::log('document.moved', $actor, $payload, $destination);
            AuditLogger::log('document.matter_moved', $actor, $payload, $destination);

            return $document->refresh();
        });
    }

    /**
     * Soft delete → per-matter trash. The row (and every version) stays;
     * status flips to 'trash'. Reversible via restore().
     *
     * A legal hold does NOT block trashing — only hard delete (DOC-28).
     */
    public static function trash(Matter $matter, Document $document, User $actor): Document
    {
        if ($document->trashed()) {
            throw new DocumentFilingException(409, 'already_trashed', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        return DB::transaction(function () use ($matter, $document, $actor): Document {
            $document->status = 'trash';
            $document->save();
            $document->delete(); // soft delete → trash

            AuditLogger::log('document.trashed', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $matter->getKey(),
            ], $matter);

            // No refresh(): the model is soft-deleted and fresh() would
            // miss it — the in-memory state (status, deleted_at) is exact.
            return $document;
        });
    }

    /**
     * Restore from trash: every version comes back intact (versions are
     * never touched by the trash path). Status is recomputed from the
     * current blob — quarantined bytes stay quarantined.
     *
     * @throws DocumentFilingException 409 not_in_trash
     */
    public static function restore(Matter $matter, Document $document, User $actor): Document
    {
        if (! $document->trashed()) {
            throw new DocumentFilingException(409, 'not_in_trash', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        return DB::transaction(function () use ($matter, $document, $actor): Document {
            $document->restore();

            $blob = $document->currentVersion?->blob;
            $document->status = ($blob !== null && $blob->quarantined) ? 'quarantined' : 'ready';
            $document->save();

            AuditLogger::log('document.restored', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $matter->getKey(),
            ], $matter);

            return $document->refresh();
        });
    }

    /**
     * Immediate hard delete (the purge command's per-document step, also
     * exposed as documents.destroy.permanent).
     *
     * Legal hold → 423 hold_locked + document.destroy.denied audit (DOC-28).
     * Otherwise the document row, its version rows (via the 007-D07 GUC
     * bypass — the single sanctioned destruction path), and orphaned blob
     * bytes are removed; released-hold rows keep their history with
     * document_id nulled.
     *
     * @throws DocumentFilingException 423 hold_locked
     */
    public static function hardDelete(Matter $matter, Document $document, ?User $actor): void
    {
        if (self::holdsBlocking($document)) {
            AuditLogger::log('document.destroy.denied', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'reason' => 'legal_hold',
            ], $matter, explicitOrgId: (string) $matter->org_id);

            throw new DocumentFilingException(423, 'hold_locked', [
                'document_id' => (string) $document->getKey(),
            ], denialAuditEvent: 'document.destroy.denied');
        }

        DB::transaction(function () use ($matter, $document, $actor): void {
            // 007-D07: the single sanctioned bypass of the
            // document_versions immutability trigger, scoped to this
            // transaction via SET LOCAL.
            DB::statement("SET LOCAL document_versions.purge_allowed = 'on'");

            $documentId = (string) $document->getKey();

            $versions = $document->versions()->with('blob')->get();

            if (Schema::hasTable('document_text_pages')) {
                DB::table('document_text_pages')
                    ->whereIn('version_id', $versions->pluck('id'))
                    ->delete();
            }

            DB::table('document_grants')->where('document_id', $documentId)->delete();
            DB::table('document_shares')->where('document_id', $documentId)->delete();

            if (Schema::hasTable('retention_flags')) {
                DB::table('retention_flags')->where('document_id', $documentId)->delete();
            }
            if (Schema::hasTable('disposition_queue')) {
                DB::table('disposition_queue')->where('document_id', $documentId)->delete();
            }

            // Released holds keep their history; the document reference is
            // nulled since the row is being destroyed. Active holds never
            // reach here (holdsBlocking above).
            LegalHold::query()
                ->where('document_id', $documentId)
                ->update(['document_id' => null]);

            $document->forceFill(['current_version_id' => null])->save();
            DB::table('document_versions')->where('document_id', $documentId)->delete();
            $document->forceDelete();

            // Blob refcount cleanup: decrement per version reference;
            // delete bytes only when nothing references them (007-D01).
            /** @var DocumentStore $store */
            $store = app(DocumentStore::class);
            $seenBlobs = [];
            foreach ($versions as $version) {
                $blobId = (string) $version->blob_id;
                if (isset($seenBlobs[$blobId])) {
                    continue;
                }
                $seenBlobs[$blobId] = true;

                /** @var DocumentBlob|null $blob */
                $blob = DocumentBlob::query()->whereKey($blobId)->first();
                if ($blob === null) {
                    continue;
                }
                $blob->decrement('refcount');
                if ((int) $blob->fresh()->refcount <= 0) {
                    $store->delete($blob->fresh());
                }
            }

            AuditLogger::log('document.destroyed', $actor, [
                'document_id' => $documentId,
                'matter_id' => (string) $matter->getKey(),
                'version_count' => $versions->count(),
            ], $matter, explicitOrgId: (string) $matter->org_id);

            // Belt-and-braces for 007-D07: if this transaction is a
            // savepoint inside a larger one, releasing it would otherwise
            // leak the bypass setting into the outer transaction.
            // (On exception the rollback discards SET LOCAL anyway.)
            DB::statement('RESET document_versions.purge_allowed');
        });
    }

    /**
     * Documents eligible for the scheduled purge: trashed at least
     * TRASH_RETENTION_DAYS ago.
     *
     * @return EloquentCollection<int, Document>
     */
    public static function purgeEligible(): EloquentCollection
    {
        return Document::query()
            ->onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(self::TRASH_RETENTION_DAYS))
            ->get();
    }

    /**
     * Scheduled hard delete (DOC-11; wired as documents:purge-trash,
     * daily). Held documents are skipped — the hold wins, and the skip is
     * audited as document.destroy.denied.
     *
     * @return array{purged: int, held: int}
     */
    public static function purgeTrash(): array
    {
        $purged = 0;
        $held = 0;

        foreach (self::purgeEligible() as $document) {
            $matter = $document->matter;

            if (! $matter instanceof Matter) {
                continue;
            }

            try {
                self::hardDelete($matter, $document, null);
                $purged++;
            } catch (DocumentFilingException $e) {
                if ($e->errorCode === 'hold_locked') {
                    $held++;

                    continue;
                }

                throw $e;
            }
        }

        return ['purged' => $purged, 'held' => $held];
    }

    /**
     * Bulk move: every document lands in the same matter folder.
     *
     * @param  list<string>  $documentIds
     *
     * @throws DocumentFilingException 422 folder_not_in_matter
     * @throws ModelNotFoundException when the folder is foreign
     */
    public static function bulkMove(Matter $matter, array $documentIds, User $actor, string $folderId): int
    {
        $folder = DocumentFolderService::resolveInMatter($matter, $folderId);

        $moved = 0;
        foreach (self::resolveAll($matter, $documentIds) as $document) {
            self::moveToFolder($matter, $document, $actor, (string) $folder->getKey());
            $moved++;
        }

        return $moved;
    }

    /**
     * Bulk tag: add or remove tags on a set of documents. Metadata-only —
     * never creates versions (DOC-19). Each document writes
     * document.metadata.updated.
     *
     * @param  list<string>  $documentIds
     * @param  list<string>  $tags
     *
     * @throws DocumentFilingException 422 invalid_tag_mode | 409 in_trash
     */
    public static function bulkTag(Matter $matter, array $documentIds, User $actor, array $tags, string $mode): int
    {
        if (! in_array($mode, ['add', 'remove'], true)) {
            throw new DocumentFilingException(422, 'invalid_tag_mode', ['mode' => $mode]);
        }

        $clean = array_values(array_unique(array_filter(array_map(
            fn (mixed $t): string => mb_substr(trim((string) $t), 0, 64),
            $tags
        ))));

        $tagged = 0;
        foreach (self::resolveAll($matter, $documentIds) as $document) {
            if ($document->trashed()) {
                throw new DocumentFilingException(409, 'in_trash', [
                    'document_id' => (string) $document->getKey(),
                ]);
            }

            /** @var list<string> $current */
            $current = $document->tags ?? [];
            $next = $mode === 'add'
                ? array_values(array_unique(array_merge($current, $clean)))
                : array_values(array_diff($current, $clean));

            DB::transaction(function () use ($matter, $document, $actor, $next, $mode): void {
                // tags is a native text[] — the PostgresTextArray cast
                // (007 T-02) serializes the literal on save.
                $document->tags = $next;
                $document->save();

                AuditLogger::log('document.metadata.updated', $actor, [
                    'document_id' => (string) $document->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'change' => 'tags.'.$mode,
                ], $matter);
            });

            $tagged++;
        }

        return $tagged;
    }

    /**
     * Bulk download: streams a ZIP of the selected documents' current
     * versions, preserving folder structure
     * (e.g. "Pleadings/Complaint — filed stamp.pdf").
     *
     * Returns the temp ZIP path; the caller streams it and deletes it.
     * Quarantined or trashed documents are rejected explicitly (422) —
     * quarantine contents are never downloadable.
     *
     * @param  list<string>  $documentIds
     * @return string temp ZIP path
     *
     * @throws DocumentFilingException 422 quarantined | in_trash | too_many_documents
     */
    public static function bulkDownloadZip(Matter $matter, array $documentIds, User $actor): string
    {
        if (count($documentIds) > self::BULK_DOWNLOAD_MAX_DOCS) {
            throw new DocumentFilingException(422, 'too_many_documents', [
                'max' => self::BULK_DOWNLOAD_MAX_DOCS,
                'given' => count($documentIds),
            ]);
        }

        /** @var DocumentStore $store */
        $store = app(DocumentStore::class);

        $documents = self::resolveAll($matter, $documentIds);
        $documents->load(['currentVersion.blob', 'folder']);

        $zipPath = tempnam(sys_get_temp_dir(), 'vl-docs-').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DocumentFilingException(500, 'zip_failed');
        }

        try {
            $usedNames = [];
            foreach ($documents as $document) {
                if ($document->trashed()) {
                    throw new DocumentFilingException(422, 'in_trash', [
                        'document_id' => (string) $document->getKey(),
                    ]);
                }

                $version = $document->currentVersion;
                $blob = $version?->blob;
                if ($version === null || $blob === null) {
                    continue; // no content yet — skip, never fail the bundle
                }
                if ($blob->quarantined) {
                    throw new DocumentFilingException(422, 'quarantined', [
                        'document_id' => (string) $document->getKey(),
                    ]);
                }

                $entryName = self::zipEntryName($document, $blob, $usedNames);
                $usedNames[$entryName] = true;
                $zip->addFromString($entryName, (string) $store->get($blob));

                AuditLogger::log('document.downloaded', $actor, [
                    'document_id' => (string) $document->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'bulk' => true,
                ], $matter);
            }
        } finally {
            $zip->close();
        }

        return $zipPath;
    }

    /**
     * ZIP entry path preserving folder structure: "Pleadings/<title>.pdf".
     * Names are sanitized (no separators/control chars) and de-duplicated.
     *
     * @param  array<string, true>  $usedNames
     */
    private static function zipEntryName(Document $document, DocumentBlob $blob, array $usedNames): string
    {
        $ext = self::MIME_EXTENSIONS[$blob->mime_sniffed] ?? 'bin';

        $title = preg_replace('/[\/\\\\\x00-\x1f]/u', '_', (string) $document->title) ?? 'document';
        $title = trim(mb_substr($title, 0, 120));
        if ($title === '') {
            $title = 'document';
        }

        $folderPath = '';
        $folder = $document->folder;
        if ($folder !== null) {
            $segments = [];
            $cursor = $folder;
            while ($cursor !== null) {
                $segments[] = preg_replace('/[\/\\\\\x00-\x1f]/u', '_', (string) $cursor->name) ?? 'folder';
                $cursor = $cursor->parent;
            }
            $folderPath = implode('/', array_reverse($segments)).'/';
        }

        $base = $folderPath.$title;
        $candidate = $base.'.'.$ext;
        $n = 2;
        while (isset($usedNames[$candidate])) {
            $candidate = $base.' ('.$n.').'.$ext;
            $n++;
        }

        return $candidate;
    }

    /**
     * Resolve a set of document ids to rows owned by this matter.
     * Unknown/foreign ids are dropped silently (404 per-id would leak
     * existence across matters; the caller asked for a set).
     *
     * @param  list<string>  $documentIds
     * @return EloquentCollection<int, Document>
     */
    private static function resolveAll(Matter $matter, array $documentIds): EloquentCollection
    {
        $ids = array_values(array_unique(array_filter(
            $documentIds,
            fn (string $id): bool => Str::isUuid($id)
        )));

        if ($ids === []) {
            return new EloquentCollection;
        }

        return Document::query()
            ->where('matter_id', $matter->getKey())
            ->whereIn('id', $ids)
            ->get();
    }
}
