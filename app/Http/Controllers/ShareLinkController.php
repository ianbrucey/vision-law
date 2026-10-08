<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\DocumentBlob;
use App\Models\DocumentShare;
use App\Services\AuditLogger;
use App\Services\DocumentShareService;
use App\Services\DocumentStore;
use App\Services\DocumentStoreException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hardened anonymous endpoint for external share links (007 T-08, DOC-25;
 * 03-contract.md §Sharing).
 *
 * - GET  /s/{token}          → show    (landing page or password form)
 * - POST /s/{token}          → unlock  (password check)
 * - POST /s/{token}/download → download (pinned version bytes)
 *
 * Minimal surface, rate-limited (routes/share.php), no session auth. Every
 * failure mode — unknown token, expired, revoked, trashed document,
 * locked-out, download disabled — renders the identical 404: no oracle
 * distinguishes them, and no title/filename/bytes ever leak.
 *
 * Leak sentinels: the token and its hash never reach logs, views, or the
 * session — the unlock flag is keyed by the share's UUID.
 */
class ShareLinkController extends Controller
{
    public function __construct(
        private readonly DocumentShareService $shares,
        private readonly DocumentStore $store,
    ) {}

    // ------------------------------------------------------------------
    // Landing page
    // ------------------------------------------------------------------

    public function show(Request $request, string $token): Response
    {
        $share = $this->shares->resolveToken($token);
        if ($share === null) {
            abort(404);
        }

        if ($share->password_hash !== null && ! $this->isUnlocked($request, $share)) {
            // Locked-out links surface 429 share_locked_out (contract
            // error catalog) — the token itself is valid, so there is no
            // existence oracle here.
            if ($share->isLockedOut()) {
                return $this->lockedOutResponse($request);
            }

            return response()->view('shares.password', [
                'locked' => false,
                'attemptsLeft' => $this->attemptsLeft($share),
            ]);
        }

        $document = $share->document;
        $version = $share->version;

        $this->auditAccess($request, $share, 'view');

        return response()->view('shares.landing', [
            'share' => $share,
            'documentTitle' => $document->title,
            'versionNumber' => $version->version_number,
            'fileSize' => $version->blob?->size,
            'token' => $token,
        ]);
    }

    // ------------------------------------------------------------------
    // Password unlock
    // ------------------------------------------------------------------

    public function unlock(Request $request, string $token): Response
    {
        $share = $this->shares->resolveToken($token);
        if ($share === null) {
            abort(404);
        }

        if ($share->password_hash === null) {
            return redirect()->route('shares.show', ['token' => $token]);
        }

        $password = (string) $request->input('password', '');

        try {
            $ok = $this->shares->attemptPassword($share, $password);
        } catch (AccessDeniedException $e) {
            // 5 wrong attempts → 15-minute lockout (contract: share_locked_out).
            $this->auditDenied($request, $share, 'locked_out');
            if ($request->expectsJson()) {
                return response()->json(['code' => 'share_locked_out'], 429);
            }

            return response()->view('shares.password', ['locked' => true, 'attemptsLeft' => 0], 429);
        }

        if (! $ok) {
            $this->auditDenied($request, $share, 'bad_password');
            if ($request->expectsJson()) {
                return response()->json(['code' => 'share_password_invalid'], 403);
            }

            return response()->view('shares.password', [
                'locked' => false,
                'attemptsLeft' => $this->attemptsLeft($share->fresh() ?? $share),
                'error' => 'Wrong password. Try again.',
            ], 403);
        }

        $request->session()->put($this->unlockKey($share), true);
        $this->auditAccess($request, $share, 'unlock');

        return redirect()->route('shares.show', ['token' => $token]);
    }

    // ------------------------------------------------------------------
    // Download (pinned version)
    // ------------------------------------------------------------------

    public function download(Request $request, string $token): StreamedResponse|Response
    {
        $share = $this->shares->resolveToken($token);
        if ($share === null) {
            abort(404);
        }

        if ($share->password_hash !== null && ! $this->isUnlocked($request, $share)) {
            if ($share->isLockedOut()) {
                return $this->lockedOutResponse($request);
            }

            abort(404);
        }

        if (! $share->allow_download) {
            // Identical 404 — the link exists but download is off; no oracle.
            abort(404);
        }

        $document = $share->document;
        $version = $share->version;
        $blob = $version->blob;
        if (! $blob instanceof DocumentBlob) {
            abort(404);
        }

        try {
            $stream = $this->store->get($blob);
        } catch (DocumentStoreException) {
            // Quarantined or missing bytes → 404, no leak.
            abort(404);
        }

        $this->auditAccess($request, $share, 'download');

        $filename = $version->original_filename ?: $document->title;
        if ($filename === '') {
            $filename = 'download';
        }
        // Non-ASCII titles (e.g. em-dashes) need an ASCII fallback —
        // makeDisposition() rejects a non-ASCII fallback outright.
        $asciiFallback = Str::ascii($filename);
        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
            $asciiFallback !== '' ? $asciiFallback : 'download'
        );

        return response()->streamDownload(function () use ($stream): void {
            while (! $stream->eof()) {
                echo $stream->read(1024 * 1024);
            }
        }, 'download', [
            'Content-Type' => (string) $blob->mime_sniffed,
            'Content-Disposition' => $disposition,
            'X-Checksum-Sha256' => (string) $blob->sha256,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function lockedOutResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => 'share_locked_out'], 429);
        }

        return response()->view('shares.password', ['locked' => true, 'attemptsLeft' => 0], 429);
    }

    /**
     * Session unlock flag — keyed by share UUID, never by the token.
     */
    private function unlockKey(DocumentShare $share): string
    {
        return 'share_unlock.'.(string) $share->getKey();
    }

    private function isUnlocked(Request $request, DocumentShare $share): bool
    {
        return $request->session()->get($this->unlockKey($share)) === true;
    }

    private function attemptsLeft(DocumentShare $share): int
    {
        $max = (int) config('document.sharing.link_lockout_attempts', 5);

        return max(0, $max - (int) $share->failed_password_attempts);
    }

    /**
     * Every access is audited with IP/timestamp (DOC-25). The actor is
     * null (anonymous) — the matter resolves the tenant. The token and
     * its hash never reach the payload (leak sentinel).
     */
    private function auditAccess(Request $request, DocumentShare $share, string $outcome): void
    {
        $matter = $share->document?->matter;

        AuditLogger::log('document.share.accessed', null, [
            'share_id' => (string) $share->getKey(),
            'document_id' => (string) $share->document_id,
            'version_id' => (string) $share->version_id,
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $matter);
    }

    /**
     * Denials (wrong password, lockout) audited without any token
     * material — only the share id and non-secret metadata.
     */
    private function auditDenied(Request $request, DocumentShare $share, string $outcome): void
    {
        $matter = $share->document?->matter;

        AuditLogger::log('document.share.denied', null, [
            'share_id' => (string) $share->getKey(),
            'document_id' => (string) $share->document_id,
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $matter);
    }
}
