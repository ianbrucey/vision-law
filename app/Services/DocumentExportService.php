<?php

namespace App\Services;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Encrypted export packages (007 T-08, DOC-26).
 *
 * Selected documents/folders are assembled into an AES-256-encrypted ZIP
 * (password supplied by the exporter, never stored, never logged) with a
 * signed JSON manifest. Assembly streams blob bytes through temp files so
 * memory stays bounded for large sets; every byte hashed is the byte
 * shipped (hash-then-add on the same temp file).
 *
 * MANIFEST FORMAT (vision-law-export-manifest/1) — embedded as
 * `manifest.json` inside the ZIP, and itself encrypted:
 *
 * {
 *   "format": "vision-law-export-manifest/1",
 *   "package_id": "<uuid>",
 *   "exported_at": "<ISO-8601>",
 *   "exporter": {"id": "<uuid>", "name": "...", "email": "..."},
 *   "matter": {"id": "<uuid>", "number": "MAT-2026-001"},
 *   "files": [
 *     {"path": "Pleadings/complaint-filed.pdf",
 *      "document_id": "<uuid>", "version_id": "<uuid>",
 *      "version_number": 3, "title": "...", "size": 1234,
 *      "sha256": "<hex>"}
 *   ],
 *   "signature": "<hex HMAC-SHA256>",
 *   "signature_note": "HMAC-SHA256 over the canonical JSON (keys sorted,
 *     no whitespace) of this manifest with the signature field removed,
 *     keyed with the exporting application's APP_KEY."
 * }
 *
 * INDEPENDENT VERIFICATION (no Vision Law needed beyond this note):
 *  1. Decrypt the ZIP with the export password.
 *  2. Completeness: every file in the ZIP except manifest.json appears
 *     in files[].path, and every files[].path exists in the ZIP.
 *  3. Integrity: recompute SHA-256 over each extracted file's bytes and
 *     compare with files[].sha256.
 *  4. Authenticity: recompute the HMAC per signature_note (requires the
 *     exporting org's key — ask the exporter; steps 1–3 need no key).
 *
 * See verify() below, which implements exactly this algorithm.
 */
class DocumentExportService
{
    public function __construct(
        private readonly DocumentStore $store,
    ) {}

    /**
     * Assemble the encrypted package.
     *
     * @param  list<string>  $documentIds  UUIDs, all inside $matter.
     * @param  list<string>  $folderIds  UUIDs, all inside $matter (their documents are included).
     * @return array{path: string, filename: string, package_id: string, file_count: int}
     *                                                                                    path = temp ZIP path (caller streams it, then deletes).
     *
     * @throws \InvalidArgumentException on bad input (422-class errors).
     * @throws \RuntimeException when the ZIP cannot be assembled.
     */
    public function export(User $actor, Matter $matter, array $documentIds, array $folderIds, string $password): array
    {
        $minPassword = (int) config('document.sharing.export_password_min_length', 12);
        if (mb_strlen($password) < $minPassword) {
            throw new \InvalidArgumentException("Export password must be at least {$minPassword} characters.");
        }

        $documents = $this->resolveDocuments($actor, $matter, $documentIds, $folderIds);

        $max = (int) config('document.sharing.export_max_documents', 200);
        if (count($documents) === 0) {
            throw new \InvalidArgumentException('Select at least one document to export.');
        }
        if (count($documents) > $max) {
            throw new \InvalidArgumentException("Export is limited to {$max} documents per package.");
        }

        $packageId = (string) Str::uuid();
        $workDir = sys_get_temp_dir().'/visionlaw-export-'.$packageId;
        if (! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            throw new \RuntimeException('Could not create export working directory.');
        }

        try {
            $zipPath = $workDir.'/package.zip';
            $manifestFiles = $this->assembleZip($actor, $matter, $packageId, $documents, $zipPath, $password, $workDir);

            AuditLogger::log('document.exported', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'package_id' => $packageId,
                'file_count' => count($manifestFiles),
                'document_ids' => array_column($manifestFiles, 'document_id'),
            ], $matter);

            return [
                'path' => $zipPath,
                'filename' => "vision-law-export-{$matter->matter_number}-".now()->format('Ymd-His').'.zip',
                'package_id' => $packageId,
                'file_count' => count($manifestFiles),
            ];
        } catch (\Throwable $e) {
            $this->deleteDirectory($workDir);
            throw $e;
        }
    }

    /**
     * Resolve + authorize every document. Each document must belong to
     * the matter and the actor needs at least :view on it (effective
     * permission via DocumentAccess — matter role or document grant).
     *
     * @param  list<string>  $documentIds
     * @param  list<string>  $folderIds
     * @return list<Document>
     */
    private function resolveDocuments(User $actor, Matter $matter, array $documentIds, array $folderIds): array
    {
        /** @var array<string, true> $ids */
        $ids = [];
        foreach ($documentIds as $id) {
            if (Str::isUuid((string) $id)) {
                $ids[(string) $id] = true;
            }
        }

        foreach ($folderIds as $folderId) {
            $folderId = (string) $folderId;
            if (! Str::isUuid($folderId)) {
                continue;
            }
            $folder = DocumentFolder::query()
                ->where('matter_id', $matter->getKey())
                ->whereKey($folderId)
                ->first();
            if ($folder === null) {
                // No existence leak: a foreign/missing id reads as not found.
                throw new AccessDeniedException(404);
            }
            $descendantIds = $this->folderAndDescendants($folder);
            $docIds = Document::query()
                ->where('matter_id', $matter->getKey())
                ->whereIn('folder_id', $descendantIds)
                ->pluck('id');
            foreach ($docIds as $docId) {
                $ids[(string) $docId] = true;
            }
        }

        $documents = [];
        foreach (array_keys($ids) as $id) {
            $document = Document::query()
                ->where('matter_id', $matter->getKey())
                ->whereKey($id)
                ->first();
            if ($document === null) {
                // No existence leak: a foreign/missing id reads as not found.
                throw new AccessDeniedException(404);
            }
            // Throws 404/403 on denial — mapped by the controller to 404.
            DocumentAccess::authorize($actor, 'view', $document);
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @return list<string> folder id + all descendant ids.
     */
    private function folderAndDescendants(DocumentFolder $folder): array
    {
        $ids = [(string) $folder->getKey()];
        $children = DocumentFolder::query()->where('parent_id', $folder->getKey())->get();
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->folderAndDescendants($child));
        }

        return $ids;
    }

    /**
     * Stream every document into the encrypted ZIP and build the manifest.
     *
     * @param  list<Document>  $documents
     * @return list<array{path: string, document_id: string, version_id: string, version_number: int, title: string, size: int, sha256: string}>
     */
    private function assembleZip(User $actor, Matter $matter, string $packageId, array $documents, string $zipPath, string $password, string $workDir): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create export ZIP.');
        }

        // AES-256 for every entry added from here on (libzip ≥ 1.2).
        $zip->setPassword($password);

        $manifestFiles = [];
        $usedPaths = [];
        // addFile() only records the path — libzip reads the bytes at
        // close() time, so staging files must survive until then.
        $stagedFiles = [];

        try {
            foreach ($documents as $document) {
                $version = $document->currentVersion;
                if ($version === null) {
                    continue;
                }
                $blob = $version->blob;
                if ($blob === null) {
                    continue;
                }

                $entryName = $this->entryName($document, $usedPaths);
                $tmpFile = $workDir.'/'.Str::random(16).'.bin';

                // Stream blob bytes to temp while hashing: the hashed
                // bytes are exactly the shipped bytes.
                $hashCtx = hash_init('sha256');
                $in = $this->store->get($blob);
                $out = fopen($tmpFile, 'wb');
                if ($out === false) {
                    throw new \RuntimeException('Could not stage export bytes.');
                }
                $size = 0;
                while (! $in->eof()) {
                    $chunk = $in->read(1024 * 1024);
                    if ($chunk === '') {
                        break;
                    }
                    hash_update($hashCtx, $chunk);
                    $written = fwrite($out, $chunk);
                    if ($written === false) {
                        fclose($out);
                        throw new \RuntimeException('Could not stage export bytes.');
                    }
                    $size += $written;
                }
                fclose($out);
                $sha256 = hash_final($hashCtx);

                if (! $zip->addFile($tmpFile, $entryName)) {
                    throw new \RuntimeException("Could not add {$entryName} to the export package.");
                }
                $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                $stagedFiles[] = $tmpFile;

                $manifestFiles[] = [
                    'path' => $entryName,
                    'document_id' => (string) $document->getKey(),
                    'version_id' => (string) $version->getKey(),
                    'version_number' => (int) $version->version_number,
                    'title' => (string) $document->title,
                    'size' => $size,
                    'sha256' => $sha256,
                ];
            }

            $manifest = [
                'format' => 'vision-law-export-manifest/1',
                'package_id' => $packageId,
                'exported_at' => now()->toIso8601String(),
                'exporter' => [
                    'id' => (string) $actor->getKey(),
                    'name' => (string) $actor->name,
                    'email' => (string) $actor->email,
                ],
                'matter' => [
                    'id' => (string) $matter->getKey(),
                    'number' => (string) $matter->matter_number,
                ],
                'files' => $manifestFiles,
                'signature_note' => 'HMAC-SHA256 over the canonical JSON (keys sorted, no whitespace) of this manifest with the signature field removed, keyed with the exporting application\'s APP_KEY.',
            ];
            $manifest['signature'] = $this->signManifest($manifest);

            $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $zip->addFromString('manifest.json', $manifestJson);
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        } finally {
            $zip->close();
            foreach ($stagedFiles as $staged) {
                if (is_file($staged)) {
                    unlink($staged);
                }
            }
        }

        return $manifestFiles;
    }

    /**
     * ZIP-internal path for a document: folder chain + filename,
     * sanitized and de-duplicated.
     *
     * @param  array<string, bool>  $usedPaths
     */
    private function entryName(Document $document, array &$usedPaths): string
    {
        $segments = [];
        $folder = $document->folder;
        while ($folder !== null) {
            array_unshift($segments, $this->sanitizeSegment((string) $folder->name));
            $folder = $folder->parent;
        }

        $filename = $document->currentVersion?->original_filename ?: ((string) $document->title);
        $segments[] = $this->sanitizeSegment($filename);

        $path = implode('/', $segments);
        $base = $path;
        $n = 1;
        while (isset($usedPaths[$path])) {
            $n++;
            $path = $base." ({$n})";
        }
        $usedPaths[$path] = true;

        return $path;
    }

    private function sanitizeSegment(string $segment): string
    {
        $clean = preg_replace('/[^\p{L}\p{N} _.\-()]/u', '_', $segment) ?? 'file';
        $clean = trim($clean, ' .');
        if ($clean === '' || $clean === '.' || $clean === '..') {
            $clean = 'file';
        }

        return mb_substr($clean, 0, 120);
    }

    /**
     * Canonical JSON: keys sorted recursively, no whitespace.
     *
     * @param  array<string, mixed>  $manifest  without the signature field.
     */
    private function canonicalJson(array $manifest): string
    {
        $sorted = $this->sortKeys($manifest);

        return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->sortKeys(...), $value);
        }
        ksort($value);

        return array_map($this->sortKeys(...), $value);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function signManifest(array $manifest): string
    {
        unset($manifest['signature']);

        return hash_hmac('sha256', $this->canonicalJson($manifest), (string) config('app.key'));
    }

    /**
     * Verify an extracted manifest independently of this service class:
     * recompute the signature per the documented algorithm and check that
     * every listed file's SHA-256 matches the supplied hashes.
     *
     * @param  array<string, mixed>  $manifest  decoded manifest.json.
     * @param  array<string, string>  $fileHashes  path => recomputed SHA-256 hex of the extracted bytes.
     */
    public function verify(array $manifest, array $fileHashes): bool
    {
        if (($manifest['format'] ?? null) !== 'vision-law-export-manifest/1') {
            return false;
        }

        $expectedSignature = $manifest['signature'] ?? null;
        if (! is_string($expectedSignature)) {
            return false;
        }

        $unsigned = $manifest;
        unset($unsigned['signature']);
        $actual = hash_hmac('sha256', $this->canonicalJson($unsigned), (string) config('app.key'));
        if (! hash_equals($expectedSignature, $actual)) {
            return false;
        }

        $files = $manifest['files'] ?? null;
        if (! is_array($files) || count($files) !== count($fileHashes)) {
            return false;
        }

        foreach ($files as $file) {
            if (! is_array($file)) {
                return false;
            }
            $path = $file['path'] ?? null;
            $sha = $file['sha256'] ?? null;
            if (! is_string($path) || ! is_string($sha)) {
                return false;
            }
            if (! isset($fileHashes[$path]) || ! hash_equals(strtolower($sha), strtolower($fileHashes[$path]))) {
                return false;
            }
        }

        return true;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
