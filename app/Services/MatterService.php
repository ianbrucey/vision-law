<?php

namespace App\Services;

use App\Events\MatterClosed;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\MatterStateException;
use App\Models\AuditEvent;
use App\Models\Matter;
use App\Models\MatterComment;
use App\Models\MatterCommentRead;
use App\Models\MatterDocumentLog;
use App\Models\MatterGrant;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Domain service for matters (spec 006). Seeded in T-01 with number
 * generation only — T-02 adds create/update/delete/restore/transition/
 * close plus the derived status summary (T-04 owns search and assignment).
 * Controllers never write matter tables directly; they go through this
 * service.
 *
 * Every mutation writes its audit event in the SAME transaction as the
 * mutation (via AuditLogger with $matter set). Guard denials write their
 * denial audit event outside the rolled-back mutation transaction, in the
 * catch block below.
 */
class MatterService
{
    /**
     * The lifecycle transition table (03-contract.md §Lifecycle transition
     * table, MAT-02). from => legal to-states. Guards beyond the table
     * (client party, notes, org-admin-only) live in transitionDenial().
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'INTAKE' => ['ACTIVE', 'CLOSED'],
        'ACTIVE' => ['DISCOVERY', 'CLOSED'],
        'DISCOVERY' => ['PRE_TRIAL', 'CLOSED'],
        'PRE_TRIAL' => ['TRIAL_SETTLEMENT', 'CLOSED'],
        'TRIAL_SETTLEMENT' => ['CLOSED'],
        'CLOSED' => ['ACTIVE', 'RETENTION_HOLD'],
        // Phase 5 owns disposition — 006 refuses with disposition_not_enabled.
        'RETENTION_HOLD' => ['DISPOSITION'],
        'DISPOSITION' => [],
    ];

    /**
     * Soft-deleted matters are restorable by org admins within this window
     * (03-contract.md; C-01).
     */
    public const RESTORE_WINDOW_DAYS = 30;

    /**
     * Attributes updatable via updateMatter(). Everything else —
     * matter_number, created_at, lifecycle_state, closed_at, template
     * fields — is immutable here (C-01): unknown keys are ignored, never
     * mass-assigned.
     *
     * @var list<string>
     */
    private const UPDATE_FIELDS = ['title', 'matter_type', 'client_name', 'description'];

    /**
     * Next MAT-YYYY-NNNN number for an org+year (006-D01, 006-D10).
     *
     * Race-safe: the MAX+1 computation runs inside a transaction holding
     * pg_advisory_xact_lock(hashtext(org_id), year), so concurrent creators
     * serialize on the lock and can never compute the same number. Numbers
     * are never reused — soft-deleted rows still occupy their numbers.
     * UNIQUE(org_id, matter_number) is the backstop.
     */
    public static function generateNumber(string $orgId, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($orgId, $year): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), ?)', [$orgId, $year]);

            $max = (int) DB::table('matters')
                ->where('org_id', $orgId)
                ->where('matter_number', 'like', "MAT-{$year}-%")
                ->selectRaw("MAX(substring(matter_number from '-(\\d+)$')::int) AS max_n")
                ->value('max_n');

            return sprintf('MAT-%d-%04d', $year, $max + 1);
        });
    }

    /**
     * Create a matter in INTAKE with a generated number (006-D03 roles are
     * enforced by the caller — MatterPolicy::create / controller).
     *
     * The creator is granted matter_owner so "My Matters" index scoping
     * includes matters they create (org_admin is implicit matter_owner
     * anyway; the row is harmless there).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     */
    public static function createMatter(string $orgId, array $attrs, User $actor): Matter
    {
        /** @var array{title: string, matter_type: string, client_name: string, description?: string|null} $validated */
        $validated = Validator::make($attrs, [
            'title' => ['required', 'string', 'max:500'],
            'matter_type' => ['required', 'string', Rule::in(Matter::MATTER_TYPES)],
            'client_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ])->validate();

        // UNIQUE(org_id, matter_number) is the backstop: on the theoretical
        // race the advisory lock didn't serialize, retry with a fresh number.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($orgId, $validated, $actor): Matter {
                    $matter = Matter::create([
                        'org_id' => $orgId,
                        'matter_number' => self::generateNumber($orgId),
                        'title' => $validated['title'],
                        'matter_type' => $validated['matter_type'],
                        'client_name' => $validated['client_name'],
                        'description' => $validated['description'] ?? null,
                        'lifecycle_state' => 'INTAKE',
                    ]);

                    MatterGrant::create([
                        'org_id' => $orgId,
                        'matter_id' => $matter->getKey(),
                        'user_id' => $actor->getKey(),
                        'role' => 'matter_owner',
                        'granted_by' => $actor->getKey(),
                    ]);

                    AuditLogger::log('matter.created', $actor, [
                        'actor_id' => (string) $actor->getKey(),
                        'matter_id' => (string) $matter->getKey(),
                        'matter_number' => $matter->matter_number,
                        'title' => $matter->title,
                    ], $matter);

                    return $matter->refresh();
                });
            } catch (QueryException $e) {
                if (! in_array($e->getCode(), ['23505', 23505], true) || $attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Matter number generation failed after 3 attempts.');
    }

    /**
     * Partial update. CLOSED matters are read-only (409 matter_closed);
     * only UPDATE_FIELDS are assignable.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function updateMatter(Matter $matter, array $attrs, User $actor): Matter
    {
        self::denyIfClosed($matter, $actor, 'matter.update');

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($attrs, [
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'matter_type' => ['sometimes', 'required', 'string', Rule::in(Matter::MATTER_TYPES)],
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        /** @var array<string, mixed> $changes */
        $changes = array_intersect_key($validated, array_flip(self::UPDATE_FIELDS));

        if ($changes === []) {
            // Nothing assignable changed (e.g. PATCH matter_number only) —
            // no-op, no audit row (C-01: 422/ignored).
            return $matter;
        }

        return DB::transaction(function () use ($matter, $changes, $actor): Matter {
            $matter->fill($changes)->save();

            AuditLogger::log('matter.updated', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'changed' => array_keys($changes),
            ], $matter);

            return $matter->refresh();
        });
    }

    /**
     * Soft delete. 409 if CLOSED (close first); 403 unless the actor is the
     * matter owner or an org admin.
     *
     * @throws MatterStateException 409 {code:"matter_closed"}
     * @throws AccessDeniedException 403 {code:"forbidden"}
     */
    public static function deleteMatter(Matter $matter, User $actor): Matter
    {
        self::denyIfClosed($matter, $actor, 'matter.delete');

        $isOwner = AccessControl::effectiveMatterRole($actor, $matter) === 'matter_owner';

        if (! $actor->hasRole('org_admin') && ! $isOwner) {
            AuditLogger::log('matter.access.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'attempted_action' => 'matter.delete',
            ], $matter);

            throw new AccessDeniedException(403, 'Only the matter owner or an org admin can delete a matter.');
        }

        if ($matter->trashed()) {
            return $matter; // idempotent
        }

        return DB::transaction(function () use ($matter, $actor): Matter {
            $matter->delete();

            AuditLogger::log('matter.deleted', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
            ], $matter);

            return $matter;
        });
    }

    /**
     * Restore a soft-deleted matter. Org admin only; 422
     * restore_window_expired when deleted more than RESTORE_WINDOW_DAYS ago.
     *
     * @throws AccessDeniedException 403 {code:"forbidden"}
     * @throws MatterStateException 422 {code:"restore_window_expired"}
     */
    public static function restoreMatter(Matter $matter, User $actor): Matter
    {
        if (! $actor->hasRole('org_admin')) {
            AuditLogger::log('matter.access.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'attempted_action' => 'matter.restore',
            ], $matter);

            throw new AccessDeniedException(403, 'Only an org admin can restore a matter.');
        }

        if (! $matter->trashed()) {
            return $matter; // nothing to restore — no-op
        }

        $deletedAt = $matter->deleted_at;

        if ($deletedAt !== null && $deletedAt->lt(now()->subDays(self::RESTORE_WINDOW_DAYS))) {
            AuditLogger::log('matter.restore.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'deleted_at' => $deletedAt->toIso8601String(),
                'denial_code' => 'restore_window_expired',
            ], $matter);

            throw new MatterStateException(422, 'restore_window_expired');
        }

        return DB::transaction(function () use ($matter, $actor): Matter {
            $matter->restore();

            AuditLogger::log('matter.restored', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
            ], $matter);

            return $matter->refresh();
        });
    }

    /**
     * Table-driven lifecycle transition with SELECT … FOR UPDATE
     * serialization. Concurrent transitions block on the row lock; the loser
     * re-reads the row and evaluates guards against the winner's state, so
     * a stale duplicate degrades to the 006-D06 same-state no-op instead of
     * a lost update.
     *
     * Audit: matter.transition ({from,to,note}) for ordinary transitions,
     * matter.closed for →CLOSED, matter.reopened for CLOSED→ACTIVE — each in
     * the same transaction as the state change.
     *
     * @throws MatterStateException 409 {code:"invalid_transition", legal_next_states:[…]}
     *                              409 {code:"disposition_not_enabled"}
     *                              422 {code:"client_party_required"|"closing_note_required"|"reopen_note_required"}
     *                              403 {code:"forbidden"} (CLOSED→RETENTION_HOLD by non-admin)
     */
    public static function transitionMatter(Matter $matter, string $to, ?string $note, User $actor): Matter
    {
        if (! in_array($to, Matter::LIFECYCLE_STATES, true)) {
            throw new \InvalidArgumentException("Unknown lifecycle state: {$to}.");
        }

        try {
            return DB::transaction(function () use ($matter, $to, $note, $actor): Matter {
                /** @var Matter $locked */
                $locked = Matter::query()
                    ->whereKey($matter->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $from = (string) $locked->lifecycle_state;

                // 006-D06: transition to the current state → 200 no-op, no audit row.
                if ($to === $from) {
                    return $locked;
                }

                $denial = self::transitionDenial($locked, $from, $to, $note, $actor);

                if ($denial instanceof MatterStateException) {
                    throw $denial;
                }

                $locked->lifecycle_state = $to;

                if ($to === 'CLOSED') {
                    $locked->closed_at = now();
                } elseif ($from === 'CLOSED' && $to === 'ACTIVE') {
                    $locked->closed_at = null; // reopen clears the closure timestamp
                }

                $locked->save();

                $event = $to === 'CLOSED'
                    ? 'matter.closed'
                    : (($from === 'CLOSED' && $to === 'ACTIVE') ? 'matter.reopened' : 'matter.transition');

                AuditLogger::log($event, $actor, [
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $locked->getKey(),
                    'from' => $from,
                    'to' => $to,
                    'note' => $note,
                ], $locked);

                return $locked->refresh();
            });
        } catch (MatterStateException $e) {
            // The mutation transaction rolled back — the denial is still a
            // security event and must be recorded (contract §Audit events).
            if ($e->denialAuditEvent !== null) {
                AuditLogger::log($e->denialAuditEvent, $actor, [
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'attempted_transition' => [
                        'from' => (string) $matter->lifecycle_state,
                        'to' => $to,
                    ],
                    'denial_code' => $e->errorCode,
                ], $matter);
            }

            throw $e;
        }
    }

    /**
     * Guided closure: closing note required (422 closing_note_required),
     * sets CLOSED + closed_at, audits matter.closed, then dispatches
     * MatterClosed (no listener in 006 — Phase 5 hook).
     *
     * Re-closing an already-CLOSED matter is a 006-D06 no-op (no audit, no
     * duplicate event).
     *
     * @throws MatterStateException 422 {code:"closing_note_required"}
     */
    public static function closeMatter(Matter $matter, ?string $note, User $actor): Matter
    {
        if ((string) $matter->lifecycle_state === 'CLOSED') {
            return $matter;
        }

        $closed = self::transitionMatter($matter, 'CLOSED', $note, $actor);

        // Dispatched only after the transition commits.
        event(new MatterClosed($closed, $note, $actor));

        return $closed;
    }

    /**
     * Derived status summary (MAT-16), computed on read — no status table
     * (T-04 extends with search-relevant fields; tasks/deadlines/holds are
     * Phase 4/5 interfaces returning their defined empty shapes).
     *
     * @return array<string, mixed>
     */
    public static function statusSummary(Matter $matter, User $user): array
    {
        $stateEnteredAt = AuditEvent::query()
            ->where('matter_id', $matter->getKey())
            ->whereIn('event', ['matter.created', 'matter.transition', 'matter.reopened', 'matter.closed'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at') ?? $matter->created_at;

        $lastReadAt = MatterCommentRead::query()
            ->where('matter_id', $matter->getKey())
            ->where('user_id', $user->getKey())
            ->value('last_read_at');

        $unreadQuery = MatterComment::query()
            ->where('matter_id', $matter->getKey())
            ->whereNull('deleted_at');

        if ($lastReadAt !== null) {
            $unreadQuery->where('created_at', '>', $lastReadAt);
        }

        $lastActivity = AuditEvent::query()
            ->where('matter_id', $matter->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $team = MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->whereNotNull('user_id')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user')
            ->get()
            ->map(fn (MatterGrant $grant): array => [
                'user' => [
                    'id' => (string) $grant->user_id,
                    'name' => $grant->user?->name,
                    'email' => $grant->user?->email,
                ],
                'role' => $grant->role,
            ])
            ->values()
            ->all();

        return [
            'lifecycle_state' => $matter->lifecycle_state,
            'days_in_state' => $stateEnteredAt !== null ? (int) now()->diffInDays($stateEnteredAt) : 0,
            'document_log' => [
                'received' => MatterDocumentLog::query()
                    ->where('matter_id', $matter->getKey())->where('direction', 'received')->count(),
                'sent' => MatterDocumentLog::query()
                    ->where('matter_id', $matter->getKey())->where('direction', 'sent')->count(),
            ],
            'tasks' => ['open' => 0, 'overdue' => 0], // Phase 4 interface
            'deadlines_next_14d' => [], // Phase 4 interface
            'unread_comments' => $unreadQuery->count(),
            'last_activity' => $lastActivity === null ? null : [
                'at' => $lastActivity->created_at?->toIso8601String(),
                'actor' => $lastActivity->actor_id !== null ? (string) $lastActivity->actor_id : null,
                'event' => $lastActivity->event,
            ],
            'assigned_team' => $team,
            'active_holds' => [], // Phase 5 interface
        ];
    }

    /**
     * Evaluate the transition table + guards against the LOCKED row.
     * Returns a denial (carrying its audit event) or null when the
     * transition may proceed.
     */
    private static function transitionDenial(Matter $locked, string $from, string $to, ?string $note, User $actor): ?MatterStateException
    {
        // Phase 5 owns disposition — 006 refuses with NO audit row
        // (contract §Error catalog).
        if ($from === 'RETENTION_HOLD' && $to === 'DISPOSITION') {
            return new MatterStateException(409, 'disposition_not_enabled');
        }

        $legal = self::TRANSITIONS[$from] ?? [];

        if (! in_array($to, $legal, true)) {
            return new MatterStateException(
                409,
                'invalid_transition',
                ['legal_next_states' => $legal],
                'matter.transition.denied'
            );
        }

        $note = trim((string) $note);

        if ($to === 'CLOSED' && $note === '') {
            return new MatterStateException(422, 'closing_note_required', [], 'matter.transition.denied');
        }

        if ($from === 'INTAKE' && $to === 'ACTIVE' && ! self::hasClientParty($locked)) {
            return new MatterStateException(422, 'client_party_required', [], 'matter.transition.denied');
        }

        if ($from === 'CLOSED') {
            // Contract §Requests & validation: note required when from CLOSED.
            // (reopen_note_required is the CLOSED→ACTIVE analogue of
            // closing_note_required — the contract names no code for it.)
            if ($note === '') {
                return new MatterStateException(422, 'reopen_note_required', [], 'matter.transition.denied');
            }

            if ($to === 'RETENTION_HOLD' && ! $actor->hasRole('org_admin')) {
                return new MatterStateException(403, 'forbidden', [], 'matter.access.denied');
            }
        }

        return null;
    }

    /**
     * INTAKE→ACTIVE guard: at least one non-deleted client party.
     */
    private static function hasClientParty(Matter $matter): bool
    {
        return MatterParty::query()
            ->where('matter_id', $matter->getKey())
            ->where('party_type', 'client')
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * CLOSED matters are read-only: any mutation except transition/restore
     * → 409 {code:"matter_closed"}, audited as matter.access.denied
     * (contract §Error catalog).
     *
     * @throws MatterStateException
     */
    private static function denyIfClosed(Matter $matter, User $actor, string $attemptedAction): void
    {
        if ((string) $matter->lifecycle_state === 'CLOSED') {
            AuditLogger::log('matter.access.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'attempted_action' => $attemptedAction,
            ], $matter);

            throw new MatterStateException(409, 'matter_closed');
        }
    }
}
