<?php

namespace App\Services;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentGrant;
use App\Models\DocumentShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Internal document grants + external secure links (007 T-08, DOC-24/25).
 *
 * Leak sentinels (00-brief.md): share tokens and token hashes never reach
 * logs — createLink() returns the plaintext token to the caller exactly
 * once; only the SHA-256 hash is persisted.
 *
 * All mutations write their audit event in the same transaction (006-D04
 * pattern): document.shared (grants and link creation), document.share.revoked.
 */
class DocumentShareService
{
    /**
     * Grant a user a document-level permission. Idempotent per
     * (document, user): re-granting updates the level.
     *
     * The grantee must belong to the document's org — grants never cross
     * the tenant boundary. Effective permission = max(matter role, grant)
     * is resolved at read time by DocumentAccess (a grant without matter
     * access is inert).
     *
     * @return array{grant: DocumentGrant, created: bool}
     */
    public function grant(User $actor, Document $document, User $grantee, string $level): array
    {
        if (! in_array($level, DocumentGrant::LEVELS, true)) {
            throw new \InvalidArgumentException("Unknown grant level: {$level}.");
        }

        if ((string) $grantee->org_id !== (string) $document->org_id) {
            throw new \InvalidArgumentException('Grantee must belong to the document\'s organization.');
        }

        return DB::transaction(function () use ($actor, $document, $grantee, $level): array {
            $grant = DocumentGrant::query()->firstOrNew([
                'document_id' => $document->getKey(),
                'user_id' => $grantee->getKey(),
            ]);

            $created = ! $grant->exists;
            $grant->fill(['level' => $level, 'created_by' => $actor->getKey()]);
            $grant->save();

            AuditLogger::log('document.shared', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'grantee_id' => (string) $grantee->getKey(),
                'level' => $level,
                'share_kind' => 'internal-grant',
            ], $document->matter);

            return ['grant' => $grant->refresh(), 'created' => $created];
        });
    }

    /**
     * Revoke a document grant. Instant; the next DocumentAccess check
     * re-reads the (now missing) row.
     */
    public function revokeGrant(User $actor, DocumentGrant $grant): void
    {
        DB::transaction(function () use ($actor, $grant): void {
            $documentId = (string) $grant->document_id;
            $granteeId = (string) $grant->user_id;
            $matter = $grant->document?->matter;

            $grant->delete();

            AuditLogger::log('document.share.revoked', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => $documentId,
                'grantee_id' => $granteeId,
                'share_kind' => 'internal-grant',
            ], $matter);
        });
    }

    /**
     * Create an external secure link pinned to a specific version.
     *
     * Returns the share row plus the plaintext token — shown to the
     * creator exactly once (contract §Component props) and never stored.
     * The token is 48 URL-safe characters (288 bits); only its SHA-256
     * hash is persisted.
     *
     * @param  array{expires_in_days?: int, password?: ?string, allow_download?: bool, version_id?: ?string}  $options
     * @return array{share: DocumentShare, token: string}
     */
    public function createLink(User $actor, Document $document, array $options = []): array
    {
        $maxDays = (int) config('document.sharing.link_expiry_max_days', 30);
        $defaultDays = (int) config('document.sharing.link_expiry_default_days', 7);
        $days = (int) ($options['expires_in_days'] ?? $defaultDays);

        if ($days < 1 || $days > $maxDays) {
            throw new \InvalidArgumentException("expires_in_days must be between 1 and {$maxDays}.");
        }

        $versionId = $options['version_id'] ?? $document->current_version_id;
        $version = $document->versions()->whereKey((string) $versionId)->first();
        if ($version === null) {
            throw new \InvalidArgumentException('version_id does not belong to this document.');
        }

        $password = $options['password'] ?? null;
        $passwordHash = null;
        if (is_string($password) && $password !== '') {
            $min = (int) config('document.sharing.link_password_min_length', 12);
            if (mb_strlen($password) < $min) {
                throw new \InvalidArgumentException("Link password must be at least {$min} characters.");
            }
            $passwordHash = Hash::make($password);
        }

        $token = Str::random(48);

        $result = DB::transaction(function () use ($actor, $document, $version, $days, $passwordHash, $token, $options): array {
            $share = DocumentShare::create([
                'document_id' => $document->getKey(),
                'version_id' => $version->getKey(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays($days),
                'password_hash' => $passwordHash,
                'allow_download' => (bool) ($options['allow_download'] ?? true),
                'created_by' => $actor->getKey(),
            ]);

            // Leak sentinel: the token and its hash NEVER reach the audit
            // log — only the share id and non-secret metadata.
            AuditLogger::log('document.shared', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'share_id' => (string) $share->getKey(),
                'version_id' => (string) $version->getKey(),
                'version_number' => $version->version_number,
                'share_kind' => 'external-link',
                'expires_at' => $share->expires_at->toIso8601String(),
                'protected' => $passwordHash !== null,
                'allow_download' => $share->allow_download,
            ], $document->matter);

            return ['share' => $share, 'token' => $token];
        });

        /** @var array{share: DocumentShare, token: string} $result */
        return $result;
    }

    /**
     * Revoke an external link instantly. The token hash row stays (so the
     * identical-404 contract holds) but isUsable() flips to false.
     */
    public function revokeLink(User $actor, DocumentShare $share): void
    {
        DB::transaction(function () use ($actor, $share): void {
            $share->forceFill(['revoked_at' => now()])->save();

            AuditLogger::log('document.share.revoked', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $share->document_id,
                'share_id' => (string) $share->getKey(),
                'share_kind' => 'external-link',
            ], $share->document?->matter);
        });
    }

    /**
     * Resolve a raw token to a usable share, or null. Expired and revoked
     * shares resolve to null so every failure mode renders the identical
     * 404 (contract §Sharing) — no oracle distinguishes them.
     *
     * The caller must never log the token or the hash (leak sentinel).
     */
    public function resolveToken(string $token): ?DocumentShare
    {
        if ($token === '' || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return null;
        }

        $share = DocumentShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($share === null || ! $share->isUsable()) {
            return null;
        }

        // A trashed document's links go dark (identical 404, no leak).
        $document = $share->document()->withTrashed()->first();
        if ($document === null || $document->trashed()) {
            return null;
        }

        // NOTE: a password-locked link still resolves here — the lockout
        // surfaces as 429 share_locked_out in the controller, not as the
        // identical 404 (only expired/revoked/unknown stay indistinguishable).
        return $share;
    }

    /**
     * Attempt a password unlock. Returns true and clears the attempt
     * counter on success. On the 5th wrong attempt the link locks for 15
     * minutes (contract error catalog: share_locked_out → 429).
     *
     * @throws AccessDeniedException 429 when the link is locked out.
     */
    public function attemptPassword(DocumentShare $share, string $password): bool
    {
        if ($share->isLockedOut()) {
            throw new AccessDeniedException(429);
        }

        if ($share->password_hash === null) {
            return true;
        }

        // A past lockout window resets the counter — after the 15 minutes
        // elapse the user gets a fresh set of attempts.
        if ($share->password_locked_until !== null) {
            $share->forceFill([
                'failed_password_attempts' => 0,
                'password_locked_until' => null,
            ])->save();
        }

        if (Hash::check($password, (string) $share->password_hash)) {
            $share->forceFill([
                'failed_password_attempts' => 0,
                'password_locked_until' => null,
            ])->save();

            return true;
        }

        $maxAttempts = (int) config('document.sharing.link_lockout_attempts', 5);
        $attempts = ((int) $share->failed_password_attempts) + 1;

        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = now()->addMinutes((int) config('document.sharing.link_lockout_minutes', 15));
        }

        $share->forceFill([
            'failed_password_attempts' => $attempts,
            'password_locked_until' => $lockedUntil,
        ])->save();

        if ($lockedUntil !== null) {
            throw new AccessDeniedException(429);
        }

        return false;
    }
}
