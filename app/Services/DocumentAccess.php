<?php

namespace App\Services;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentGrant;
use App\Models\Matter;
use App\Models\User;

/**
 * Document-level authorization (007 T-08, DOC-24).
 *
 * Effective permission = max(matter role, document grant). The matter is
 * always the authorization boundary (006): a document grant is inert
 * without matter access, and matter-access revocation kills document
 * access at read time.
 *
 * Read-fresh, never cached: both the matter role
 * (AccessControl::effectiveMatterRole) and the document grant row are
 * re-queried on every call, so revocation takes effect immediately.
 *
 * Grant level → actions: viewer → view; commenter → view+comment;
 * editor → view+comment+edit. A grant never confers :manage (matter
 * managers only).
 */
class DocumentAccess
{
    /**
     * Document actions a grant level confers, least → most permissive.
     *
     * @var array<string, list<string>>
     */
    public const GRANT_LEVEL_ACTIONS = [
        'viewer' => ['view'],
        'commenter' => ['view', 'comment'],
        'editor' => ['view', 'comment', 'edit'],
    ];

    /**
     * The document actions the user may perform: view < comment < edit < manage.
     *
     * @var list<string>
     */
    public const ACTIONS = ['view', 'comment', 'edit', 'manage'];

    /**
     * Authorize a document action. Returns void on allow.
     *
     * @throws AccessDeniedException 404 when the actor has no matter
     *                               access at all (no existence leak —
     *                               the document must not be revealed),
     *                               403 when the document is visible but
     *                               the effective permission is
     *                               insufficient for the action.
     * @throws \InvalidArgumentException on an unknown action (programmer error)
     */
    public static function authorize(User $user, string $action, Document $document): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown document action: {$action}.");
        }

        $matter = $document->matter;

        // 404 — not 403 — for every case where the actor must not learn
        // the document exists: missing matter, cross-org, deactivated.
        if (! $matter instanceof Matter
            || (string) $user->org_id !== (string) $matter->org_id
            || $user->deactivated_at !== null
        ) {
            throw new AccessDeniedException(404);
        }

        // Matter access is re-checked on EVERY call — a revoked matter
        // grant kills document access immediately, even when a document
        // grant row still exists (never trust a cached/stale grant).
        if (AccessControl::effectiveMatterRole($user, $matter) === null) {
            throw new AccessDeniedException(404);
        }

        // The matter role may already satisfy the action (the common
        // case). effectiveMatterRole() returned non-null above, so a
        // denial here is strictly 403 (visible matter, weak role).
        try {
            AccessControl::authorize($user, $action, $matter);

            return;
        } catch (AccessDeniedException) {
            // Fall through to the document-grant check.
        }

        // Document grant: fresh read every time, never cached.
        $level = DocumentGrant::query()
            ->where('document_id', $document->getKey())
            ->where('user_id', $user->getKey())
            ->value('level');

        $grantActions = self::GRANT_LEVEL_ACTIONS[(string) $level] ?? [];

        if (! in_array($action, $grantActions, true)) {
            throw new AccessDeniedException(403);
        }
    }

    /**
     * Non-throwing variant for UI gating.
     */
    public static function can(User $user, string $action, Document $document): bool
    {
        try {
            self::authorize($user, $action, $document);

            return true;
        } catch (AccessDeniedException|\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The user's direct document grant level, if any (fresh read).
     */
    public static function grantLevel(User $user, Document $document): ?string
    {
        $level = DocumentGrant::query()
            ->where('document_id', $document->getKey())
            ->where('user_id', $user->getKey())
            ->value('level');

        return is_string($level) ? $level : null;
    }
}
