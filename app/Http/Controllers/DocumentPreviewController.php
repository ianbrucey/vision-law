<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\DocumentSignedUrl;
use App\Services\DocumentStore;
use App\Services\OfficePreviewService;
use App\Services\SignedUrlException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document preview + download (spec 007 T-03; 03-contract.md §Documents).
 *
 * - GET /matters/{matter}/documents/{document}/preview        → show (page)
 * - GET /matters/{matter}/documents/{document}/preview/file   → file (bytes)
 * - GET /matters/{matter}/documents/{document}/download       → download
 *
 * The page route carries matter.access:view. The byte routes are
 * authorized by HMAC-signed URLs (15-min, scoped to the user) AND a
 * from-scratch permission re-check on every request — including range
 * requests — so revoking access kills outstanding URLs immediately.
 * Denials on the byte routes are always 403 (the presenter already knew
 * the document: forbidden-but-known); error bodies carry bare codes
 * only, never titles, filenames, or bytes (leak sentinels).
 *
 * Quarantined documents → 403 `quarantined` on preview and download
 * (contract error catalog; T-02 wired the store-layer refusal).
 */
class DocumentPreviewController extends Controller
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly DocumentSignedUrl $signedUrl,
        private readonly OfficePreviewService $office,
    ) {}

    // ------------------------------------------------------------------
    // Preview page
    // ------------------------------------------------------------------

    public function show(Request $request, string $matter, string $document): mixed
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if ($doc === null) {
            abort(404);
        }

        $this->denyIfQuarantined($doc, $actor, $matterModel, 'document.access.denied');

        $version = $doc->currentVersion;
        if (! $version instanceof DocumentVersion) {
            abort(404);
        }

        AuditLogger::log('document.previewed', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'document_id' => (string) $doc->getKey(),
            'version_id' => (string) $version->getKey(),
            'version_number' => $version->version_number,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $matterModel);

        $target = $this->resolvePreviewTarget($version);
        $fileQuery = $this->signedUrl->generate($actor, $doc, (string) $version->getKey());
        $downloadQuery = $this->signedUrl->generate($actor, $doc, (string) $version->getKey());

        return view('documents.preview', [
            'matter' => $matterModel,
            'document' => $doc,
            'version' => $version,
            'previewMode' => $target['mode'],
            'fileUrl' => route('documents.preview.file', [$matterModel, $doc]).'?'.$fileQuery,
            'downloadUrl' => route('documents.download', [$matterModel, $doc]).'?'.$downloadQuery,
            'pageCount' => $version->page_count,
            'metadata' => $doc->metadata ?? [],
            // 007 T-06: search-result deep link (?page= + highlight=).
            // The pdf.js viewer already honors ?page= from location.search;
            // highlight pre-fills the find box via __previewConfig.
            'initialPage' => max(1, $request->integer('page', 1)),
            'highlight' => mb_substr($request->string('highlight')->toString(), 0, 200),
            'previewConfig' => [
                'fileUrl' => route('documents.preview.file', [$matterModel, $doc]).'?'.$fileQuery,
                'pageCount' => $version->page_count,
                'initialPage' => max(1, $request->integer('page', 1)),
                'highlight' => mb_substr($request->string('highlight')->toString(), 0, 200),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Preview bytes (signed URL; permission re-checked per request)
    // ------------------------------------------------------------------

    public function file(Request $request, string $matter, string $document): StreamedResponse
    {
        [$actor, $matterModel, $doc, $version] = $this->authorizeSignedRequest($request, $matter, $document);

        $target = $this->resolvePreviewTarget($version);

        if ($target['mode'] === 'unavailable' || ! $target['blob'] instanceof DocumentBlob) {
            AuditLogger::log('document.access.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $doc->getKey(),
                'version_id' => (string) $version->getKey(),
                'reason' => 'preview_unavailable',
            ], $matterModel, explicitOrgId: (string) $actor->org_id);

            abort(response()->json(['code' => 'preview_unavailable'], 409));
        }

        $mime = $target['mime'];
        $blob = $target['blob'];

        // Downscaled variant for progressive image loading (?downscale=N).
        if ($target['mode'] === 'image' && $request->query('downscale') !== null) {
            $downscaled = $this->downscaleImage($blob, (int) $request->query('downscale'));
            if ($downscaled !== null) {
                [$bytes, $mime] = $downscaled;

                return $this->byteResponse($bytes, 200, [
                    'Content-Type' => $mime,
                    'Content-Length' => (string) strlen($bytes),
                    'Content-Disposition' => 'inline',
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, max-age=600',
                ]);
            }
        }

        return $this->rangedStreamResponse($request, $blob, $mime, 'inline');
    }

    // ------------------------------------------------------------------
    // Download (signed URL; exact original bytes, audited)
    // ------------------------------------------------------------------

    public function download(Request $request, string $matter, string $document): StreamedResponse
    {
        [$actor, $matterModel, $doc, $version] = $this->authorizeSignedRequest($request, $matter, $document);

        $blob = $version->blob;
        if (! $blob instanceof DocumentBlob) {
            abort(404);
        }

        $filename = $version->original_filename ?: $doc->title;
        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename !== '' ? $filename : 'download'
        );

        AuditLogger::log('document.downloaded', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'document_id' => (string) $doc->getKey(),
            'version_id' => (string) $version->getKey(),
            'version_number' => $version->version_number,
            'size_bytes' => $blob->size,
            'mime' => (string) $blob->mime_sniffed,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $matterModel);

        $response = $this->rangedStreamResponse($request, $blob, (string) $blob->mime_sniffed, null);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('X-Checksum-Sha256', (string) $blob->sha256);

        return $response;
    }

    // ------------------------------------------------------------------
    // Signed-request authorization (shared by file + download)
    // ------------------------------------------------------------------

    /**
     * @return array{User, Matter, Document, DocumentVersion}
     */
    private function authorizeSignedRequest(Request $request, string $matter, string $document): array
    {
        $actor = $this->actor($request);

        try {
            $params = $this->signedUrl->verify($request->query->all(), $actor);
        } catch (SignedUrlException $e) {
            abort(response()->json(['code' => $e->errorCode], 403));
        }

        // The signature binds the document; the route must agree.
        if ($params['document_id'] !== $document) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        $matterModel = $this->resolveMatter($matter);
        $doc = $matterModel === null ? null : $this->resolveDocument($matterModel, $document);

        if ($doc === null || (string) $doc->getKey() !== $params['document_id']) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        // Permission re-checked from scratch on EVERY request (revocation
        // kills outstanding URLs). Any denial → 403: the presenter held a
        // valid signature, so the document is known to them.
        try {
            AccessControl::authorize($actor, 'view', $matterModel);
        } catch (AccessDeniedException) {
            $this->auditDenied($actor, $matterModel, $doc, null, 'forbidden');
            abort(response()->json(['code' => 'forbidden'], 403));
        }

        // Document-level grants (T-08) compose on top of the matter check
        // when the table exists; absent grants change nothing.
        $version = $this->resolveVersion($doc, $params['version_id']);
        if ($version === null) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        $blob = $version->blob;
        if ($doc->status === 'quarantined' || ($blob instanceof DocumentBlob && $blob->quarantined)) {
            $this->auditDenied($actor, $matterModel, $doc, $version, 'quarantined');
            abort(response()->json(['code' => 'quarantined'], 403));
        }

        return [$actor, $matterModel, $doc, $version];
    }

    /**
     * Decide what the previewer shows for a version.
     *
     * @return array{mode: string, blob: ?DocumentBlob, mime: ?string}
     */
    private function resolvePreviewTarget(DocumentVersion $version): array
    {
        $blob = $version->blob;
        if (! $blob instanceof DocumentBlob) {
            return ['mode' => 'unavailable', 'blob' => null, 'mime' => null];
        }

        $mime = (string) $blob->mime_sniffed;

        if ($mime === 'application/pdf') {
            return ['mode' => 'pdf', 'blob' => $blob, 'mime' => $mime];
        }

        if (OfficePreviewService::convertible($mime)) {
            $converted = $this->office->ensurePdf($version);

            return $converted instanceof DocumentBlob
                ? ['mode' => 'pdf', 'blob' => $converted, 'mime' => 'application/pdf']
                : ['mode' => 'unavailable', 'blob' => null, 'mime' => null];
        }

        if ($mime === 'image/png' || $mime === 'image/jpeg') {
            return ['mode' => 'image', 'blob' => $blob, 'mime' => $mime];
        }

        if (in_array($mime, ['text/plain', 'text/csv', 'message/rfc822'], true)) {
            return ['mode' => 'text', 'blob' => $blob, 'mime' => 'text/plain; charset=utf-8'];
        }

        return ['mode' => 'unavailable', 'blob' => null, 'mime' => null];
    }

    /**
     * GD-downscaled variant of an image blob for progressive loading.
     *
     * @return ?array{string, string} [bytes, mime]
     */
    private function downscaleImage(DocumentBlob $blob, int $maxDimension): ?array
    {
        $maxDimension = max(64, min($maxDimension, 2048));

        try {
            $bytes = $this->store->get($blob)->getContents();
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1.0, $maxDimension / max($width, $height));
            $target = $image;
            if ($scale < 1.0) {
                $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
                imagedestroy($image);
                if ($resized === false) {
                    return null;
                }
                $target = $resized;
            }

            ob_start();
            $mime = (string) $blob->mime_sniffed;
            if ($mime === 'image/png') {
                imagepng($target);
            } else {
                imagejpeg($target, null, 82);
                $mime = 'image/jpeg';
            }
            $out = (string) ob_get_clean();
            imagedestroy($target);

            return [$out, $mime];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Stream blob bytes with single-range support (pdf.js fetches pages
     * via Range requests).
     */
    private function rangedStreamResponse(
        Request $request,
        DocumentBlob $blob,
        string $mime,
        ?string $disposition,
    ): StreamedResponse {
        $size = (int) $blob->size;
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $request->header('Range');
        if (is_string($range) && preg_match('/bytes=(\\d*)-(\\d*)/', $range, $m)) {
            $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

            if ($start > $end || $start >= $size) {
                return new StreamedResponse(null, 416, ['Content-Range' => "bytes */{$size}"]);
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $store = $this->store;

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=600',
        ];
        if ($disposition !== null) {
            $headers['Content-Disposition'] = $disposition;
        }
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($store, $blob, $start, $length): void {
            $stream = $store->get($blob);
            $stream->seek($start);
            $remaining = $length;
            while ($remaining > 0 && ! $stream->eof()) {
                $chunk = $stream->read(min(65536, $remaining));
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        }, $status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function byteResponse(string $bytes, int $status, array $headers): StreamedResponse
    {
        return new StreamedResponse(function () use ($bytes): void {
            echo $bytes;
        }, $status, $headers);
    }

    private function resolveMatter(string $raw): ?Matter
    {
        if (! Str::isUuid($raw)) {
            return null;
        }

        return Matter::query()->whereKey($raw)->first();
    }

    private function resolveDocument(Matter $matter, string $raw): ?Document
    {
        if (! Str::isUuid($raw)) {
            return null;
        }

        return Document::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->whereNull('deleted_at')
            ->with(['currentVersion.blob'])
            ->first();
    }

    private function resolveVersion(Document $document, ?string $versionId): ?DocumentVersion
    {
        if ($versionId !== null) {
            if (! Str::isUuid($versionId)) {
                return null;
            }

            $version = DocumentVersion::query()
                ->where('document_id', $document->getKey())
                ->whereKey($versionId)
                ->with('blob')
                ->first();

            return $version instanceof DocumentVersion ? $version : null;
        }

        $current = $document->currentVersion;

        return $current instanceof DocumentVersion ? $current : null;
    }

    private function denyIfQuarantined(Document $doc, User $actor, Matter $matter, string $event): void
    {
        if ($doc->status !== 'quarantined') {
            return;
        }

        $this->auditDenied($actor, $matter, $doc, null, 'quarantined', $event);
        abort(response()->json(['code' => 'quarantined'], 403));
    }

    private function auditDenied(
        User $actor,
        Matter $matter,
        Document $doc,
        ?DocumentVersion $version,
        string $reason,
        string $event = 'document.access.denied',
    ): void {
        AuditLogger::log($event, $actor, [
            'actor_id' => (string) $actor->getKey(),
            'document_id' => (string) $doc->getKey(),
            'version_id' => $version === null ? null : (string) $version->getKey(),
            'reason' => $reason,
        ], $matter, explicitOrgId: (string) $actor->org_id);
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
