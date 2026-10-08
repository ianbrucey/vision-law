<?php

namespace App\Services;

use App\Exceptions\RetentionException;
use App\Models\Document;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Legal holds (007 T-09, DOC-28).
 *
 * A hold scoped to a matter or a document blocks hard delete (423),
 * version purge, and matter archival until released. Placing or
 * releasing a hold requires the legal-hold role (403 otherwise); release
 * additionally requires a logged reason.
 *
 * The block itself is consulted by DocumentFilingService::holdsBlocking
 * (destroy/purge paths); matter archival consults
 * matterArchivalBlocked(). Hold history is never deleted — released
 * holds keep their rows with released_at set.
 */
class LegalHoldService
{
    /**
     * @throws RetentionException 403 legal_hold_role_required
     */
    public static function requireLegalHoldRole(User $actor): void
    {
        if (! $actor->hasRole('legal_hold')) {
            throw new RetentionException(403, 'forbidden', [
                'reason' => 'legal_hold_role_required',
            ]);
        }
    }

    /**
     * Place a hold on one document. The document must belong to the given
     * matter (006 convention: the matter is the authorization boundary).
     *
     * @throws RetentionException 403 legal_hold_role_required | 422 reason_required | 409 hold_already_active
     */
    public static function placeDocumentHold(User $actor, Matter $matter, Document $document, string $reason): LegalHold
    {
        self::requireLegalHoldRole($actor);

        if ((string) $document->matter_id !== (string) $matter->getKey()) {
            throw new RetentionException(422, 'document_not_in_matter');
        }

        return self::place($actor, $matter, $document->getKey(), $reason);
    }

    /**
     * Place a hold on a whole matter (covers every document in it).
     *
     * @throws RetentionException 403 legal_hold_role_required | 422 reason_required | 409 hold_already_active
     */
    public static function placeMatterHold(User $actor, Matter $matter, string $reason): LegalHold
    {
        self::requireLegalHoldRole($actor);

        return self::place($actor, $matter, null, $reason);
    }

    /**
     * @param  string|null  $documentId  null for a matter-level hold
     *
     * @throws RetentionException 422 reason_required | 409 hold_already_active
     */
    private static function place(User $actor, Matter $matter, ?string $documentId, string $reason): LegalHold
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RetentionException(422, 'reason_required');
        }

        return DB::transaction(function () use ($actor, $matter, $documentId, $reason): LegalHold {
            $duplicate = LegalHold::query()
                ->active()
                ->where('org_id', $matter->org_id)
                ->where('matter_id', $matter->getKey())
                ->when(
                    $documentId === null,
                    fn ($query) => $query->whereNull('document_id'),
                    fn ($query) => $query->where('document_id', $documentId)
                )
                ->exists();

            if ($duplicate) {
                throw new RetentionException(409, 'hold_already_active');
            }

            /** @var LegalHold $hold */
            $hold = LegalHold::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'document_id' => $documentId,
                'reason' => $reason,
                'created_by' => $actor->getKey(),
            ]);

            AuditLogger::log('document.hold.placed', $actor, [
                'hold_id' => (string) $hold->getKey(),
                'document_id' => $documentId === null ? null : (string) $documentId,
                'matter_id' => (string) $matter->getKey(),
            ], $matter);

            return $hold;
        });
    }

    /**
     * Release a hold. Requires the legal-hold role AND a logged reason;
     * the row is retained with released_at/by/reason for the audit trail.
     *
     * @throws RetentionException 403 legal_hold_role_required | 422 reason_required | 409 hold_already_released
     */
    public static function releaseHold(User $actor, LegalHold $hold, string $reason): LegalHold
    {
        self::requireLegalHoldRole($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RetentionException(422, 'reason_required');
        }

        if ($hold->released_at !== null) {
            throw new RetentionException(409, 'hold_already_released');
        }

        return DB::transaction(function () use ($actor, $hold, $reason): LegalHold {
            $hold->forceFill([
                'released_at' => now(),
                'released_by' => $actor->getKey(),
                'release_reason' => $reason,
            ])->save();

            $matter = $hold->matter;

            AuditLogger::log('document.hold.released', $actor, [
                'hold_id' => (string) $hold->getKey(),
                'document_id' => $hold->document_id === null ? null : (string) $hold->document_id,
                'matter_id' => $hold->matter_id === null ? null : (string) $hold->matter_id,
            ], $matter instanceof Matter ? $matter : null, explicitOrgId: (string) $hold->org_id);

            return $hold->refresh();
        });
    }

    /**
     * Whether an active hold blocks destruction of this document
     * (document-level or matter-level hold). Consulted by the destroy and
     * purge paths before the 007-D07 bypass is ever armed.
     */
    public static function holdsBlocking(Document $document): bool
    {
        return DocumentFilingService::holdsBlocking($document);
    }

    /**
     * Matter archival (006 lifecycle transition) must consult this: an
     * active matter-level hold blocks it.
     */
    public static function matterArchivalBlocked(Matter $matter): bool
    {
        return LegalHold::query()
            ->active()
            ->where('org_id', $matter->org_id)
            ->where('matter_id', $matter->getKey())
            ->whereNull('document_id')
            ->exists();
    }

    /**
     * Active holds for one matter (matter-level row + document rows) —
     * the matter-level hold list for the UI.
     *
     * @return EloquentCollection<int, LegalHold>
     */
    public static function activeHoldsForMatter(Matter $matter): EloquentCollection
    {
        return LegalHold::query()
            ->active()
            ->where('org_id', $matter->org_id)
            ->where('matter_id', $matter->getKey())
            ->with(['document:id,title', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Active holds for the whole org (admin hold management).
     *
     * @return EloquentCollection<int, LegalHold>
     */
    public static function activeHoldsForOrg(string $orgId): EloquentCollection
    {
        return LegalHold::query()
            ->active()
            ->where('org_id', $orgId)
            ->with(['matter:id,title', 'document:id,title', 'creator:id,name'])
            ->orderBy('created_at')
            ->get();
    }
}
