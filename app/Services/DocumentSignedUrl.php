<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;

/**
 * HMAC-signed preview/download URLs (spec 007 T-03, DOC-05/C-05).
 *
 * - 15-minute expiry (configurable per call).
 * - Scoped to the user: the embedded user id must match the currently
 *   authenticated user, so a signed URL cannot be forwarded.
 * - The signature authenticates the parameters only. Authorization is
 *   re-checked from scratch on EVERY request — including range requests
 *   — so revoking a grant kills outstanding URLs immediately.
 *
 * Error responses never carry titles, filenames, or bytes (leak
 * sentinels, 00-brief.md); failures surface as bare codes.
 */
class DocumentSignedUrl
{
    /**
     * Build the signed query string for a preview/download URL.
     *
     * @return string query string without the leading "?"
     */
    public function generate(User $user, Document $document, ?string $versionId = null, int $ttlMinutes = 15): string
    {
        $params = [
            'u' => (string) $user->getKey(),
            'd' => (string) $document->getKey(),
            'v' => $versionId ?? '',
            'exp' => (string) (time() + $ttlMinutes * 60),
        ];
        $params['sig'] = $this->sign($params);

        return http_build_query($params);
    }

    /**
     * Verify query parameters against the current user.
     *
     * @param  array<string, mixed>  $params  typically $request->query()
     * @return array{user_id: string, document_id: string, version_id: ?string}
     *
     * @throws SignedUrlException
     */
    public function verify(array $params, User $currentUser): array
    {
        $userId = isset($params['u']) && is_string($params['u']) ? $params['u'] : '';
        $documentId = isset($params['d']) && is_string($params['d']) ? $params['d'] : '';
        $versionId = isset($params['v']) && is_string($params['v']) && $params['v'] !== '' ? $params['v'] : null;
        $exp = isset($params['exp']) && is_string($params['exp']) ? $params['exp'] : '';
        $sig = isset($params['sig']) && is_string($params['sig']) ? $params['sig'] : '';

        if ($userId === '' || $documentId === '' || $exp === '' || $sig === '') {
            throw new SignedUrlException('invalid_signature');
        }

        if (! ctype_digit($exp) || (int) $exp < time()) {
            throw new SignedUrlException('signature_expired');
        }

        $expected = $this->sign(['u' => $userId, 'd' => $documentId, 'v' => $versionId ?? '', 'exp' => $exp]);

        if (! hash_equals($expected, $sig)) {
            throw new SignedUrlException('invalid_signature');
        }

        if ($userId !== (string) $currentUser->getKey()) {
            throw new SignedUrlException('signature_user_mismatch');
        }

        return ['user_id' => $userId, 'document_id' => $documentId, 'version_id' => $versionId];
    }

    /**
     * @param  array{u: string, d: string, v: string, exp: string}  $params
     */
    private function sign(array $params): string
    {
        $canonical = implode('|', [$params['u'], $params['d'], $params['v'], $params['exp']]);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }
}
