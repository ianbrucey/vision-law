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
use App\Models\MatterLink;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Domain service for matters (spec 006). Seeded in T-01 with number
 * generation only — T-02 adds create/update/delete/restore/transition/
 * close plus the derived status summary; T-03 adds parties, comments,
 * links, and the document log; T-04 adds assignment, search, and timeline
 * reads. Controllers never write matter tables directly; they go through
 * this service.
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

        // 006-D11: 'owner' is the contract name for 'matter_owner' (same
        // rank) — compare by rank, not by stored string, so contract-named
        // owner grants satisfy the owner check (Ticket 6 matrix caught the
        // strict comparison denying them).
        $isOwner = AccessControl::roleSatisfies(
            AccessControl::effectiveMatterRole($actor, $matter),
            'matter_owner'
        );

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
    // ── T-03: parties ──────────────────────────────────────────────────

    /**
     * Add a party to a matter (03-contract.md; C-04).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function addParty(Matter $matter, array $attrs, User $actor): MatterParty
    {
        self::denyIfClosed($matter, $actor, 'matter.party.add');

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($attrs, [
            'party_type' => ['required', 'string', Rule::in(MatterParty::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'role_description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'user_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('org_id', $matter->org_id),
            ],
        ])->validate();

        return DB::transaction(function () use ($matter, $validated, $actor): MatterParty {
            $party = MatterParty::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'party_type' => $validated['party_type'],
                'name' => $validated['name'],
                'role_description' => $validated['role_description'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
            ]);

            AuditLogger::log('matter.party.added', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'party_id' => (string) $party->getKey(),
                'party_type' => $party->party_type,
                'name' => $party->name,
            ], self::externalFlag($actor)), $matter);

            return $party->refresh();
        });
    }

    /**
     * Soft-delete a party (the row survives for the audit trail).
     *
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function removeParty(Matter $matter, MatterParty $party, User $actor): MatterParty
    {
        self::denyIfClosed($matter, $actor, 'matter.party.remove');

        return DB::transaction(function () use ($matter, $party, $actor): MatterParty {
            $party->delete();

            AuditLogger::log('matter.party.removed', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'party_id' => (string) $party->getKey(),
            ], self::externalFlag($actor)), $matter);

            return $party;
        });
    }

    // ── T-03: comments ─────────────────────────────────────────────────

    /**
     * Author edit window for comments (03-contract.md; C-06).
     */
    public const COMMENT_EDIT_WINDOW_HOURS = 24;

    /**
     * Add a comment; one-level threading (a reply's parent must itself be
     * top-level — 422 reply_depth_exceeded). @email mentions are parsed
     * against org users and recorded via matter.mentioned (006-D08: no
     * delivery in 006).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 422 {code:"reply_depth_exceeded"}
     *                              409 {code:"matter_closed"}
     */
    public static function addComment(Matter $matter, array $attrs, User $actor): MatterComment
    {
        self::denyIfClosed($matter, $actor, 'matter.comment.add');

        /** @var array{body: string, parent_id?: string|null} $validated */
        $validated = Validator::make($attrs, [
            'body' => ['required', 'string', 'max:20000'],
            'parent_id' => ['nullable', 'uuid'],
        ])->validate();

        $parentId = $validated['parent_id'] ?? null;

        if ($parentId !== null) {
            $parent = MatterComment::query()
                ->where('matter_id', $matter->getKey())
                ->whereKey($parentId)
                ->first();

            if (! $parent instanceof MatterComment) {
                throw ValidationException::withMessages([
                    'parent_id' => ['The selected parent comment is invalid.'],
                ]);
            }

            if ($parent->parent_id !== null) {
                // Reply to a reply — one-level threading only.
                throw new MatterStateException(422, 'reply_depth_exceeded');
            }
        }

        return DB::transaction(function () use ($matter, $validated, $parentId, $actor): MatterComment {
            $comment = MatterComment::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'parent_id' => $parentId,
                'author_id' => $actor->getKey(),
                'body' => $validated['body'],
            ]);

            AuditLogger::log('matter.comment.added', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'comment_id' => (string) $comment->getKey(),
                'parent_id' => $parentId !== null ? (string) $parentId : null,
            ], self::externalFlag($actor)), $matter);

            $mentionedIds = self::parseMentionedUserIds($validated['body'], (string) $matter->org_id);

            if ($mentionedIds !== []) {
                AuditLogger::log('matter.mentioned', $actor, array_merge([
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'comment_id' => (string) $comment->getKey(),
                    'mentioned_user_ids' => $mentionedIds,
                ], self::externalFlag($actor)), $matter);
            }

            return $comment->refresh();
        });
    }

    /**
     * Edit a comment body. The author may edit within COMMENT_EDIT_WINDOW_HOURS
     * (422 edit_window_expired after that); the manage path ($asManager) is
     * not time-limited. Sets edited_at (the "edited" badge).
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 422 {code:"edit_window_expired"}
     *                              409 {code:"matter_closed"}
     */
    public static function editComment(Matter $matter, MatterComment $comment, string $body, User $actor, bool $asManager): MatterComment
    {
        self::denyIfClosed($matter, $actor, 'matter.comment.edit');

        /** @var array{body: string} $validated */
        $validated = Validator::make(
            ['body' => $body],
            ['body' => ['required', 'string', 'max:20000']]
        )->validate();

        if (! $asManager) {
            $createdAt = $comment->created_at;

            if ($createdAt === null || $createdAt->lt(now()->subHours(self::COMMENT_EDIT_WINDOW_HOURS))) {
                // Pre-mutation guard denial — no audit row (contract
                // §Error catalog).
                throw new MatterStateException(422, 'edit_window_expired');
            }
        }

        return DB::transaction(function () use ($matter, $comment, $validated, $actor): MatterComment {
            $comment->forceFill([
                'body' => $validated['body'],
                'edited_at' => now(),
            ])->save();

            AuditLogger::log('matter.comment.edited', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'comment_id' => (string) $comment->getKey(),
            ], self::externalFlag($actor)), $matter);

            return $comment->refresh();
        });
    }

    /**
     * Tombstone a comment: soft delete — the row and its author are
     * retained for the audit trail.
     *
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function deleteComment(Matter $matter, MatterComment $comment, User $actor): MatterComment
    {
        self::denyIfClosed($matter, $actor, 'matter.comment.delete');

        return DB::transaction(function () use ($matter, $comment, $actor): MatterComment {
            $comment->delete();

            AuditLogger::log('matter.comment.deleted', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'comment_id' => (string) $comment->getKey(),
            ], self::externalFlag($actor)), $matter);

            return $comment;
        });
    }

    /**
     * Parse @email mentions in a comment body against the org's users.
     * Returns the mentioned user ids (strings), deduplicated.
     *
     * @return list<string>
     */
    public static function parseMentionedUserIds(string $body, string $orgId): array
    {
        preg_match_all('/@([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $body, $matches);

        /** @var list<string> $emails */
        $emails = array_values(array_unique(array_map('strtolower', $matches[1])));

        if ($emails === []) {
            return [];
        }

        /** @var array<int, string> $ids */
        $ids = User::query()
            ->where('org_id', $orgId)
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return array_values($ids);
    }

    // ── T-03: related-matter links ─────────────────────────────────────

    /**
     * Link two matters (MAT-17). Canonical ordering (matter_id <
     * related_matter_id) is service-enforced; the link is stored once and
     * reads from either side. Linking to the matter itself → 422 self_link;
     * linking to a matter the actor cannot access (or that doesn't exist in
     * the org) → 404, no existence leak. Duplicate links are idempotent
     * (200, no audit row).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 422 {code:"self_link"}
     *                              409 {code:"matter_closed"}
     * @throws AccessDeniedException 404 {code:"not_found"}
     */
    public static function addLink(Matter $matter, array $attrs, User $actor): MatterLink
    {
        self::denyIfClosed($matter, $actor, 'matter.link.add');

        /** @var array{related_matter_id: string, link_type: string, note?: string|null} $validated */
        $validated = Validator::make($attrs, [
            'related_matter_id' => ['required', 'uuid'],
            'link_type' => ['required', 'string', Rule::in(MatterLink::TYPES)],
            'note' => ['nullable', 'string'],
        ])->validate();

        $matterId = (string) $matter->getKey();
        $relatedId = (string) $validated['related_matter_id'];

        if ($relatedId === $matterId) {
            throw new MatterStateException(422, 'self_link');
        }

        $related = Matter::query()
            ->where('org_id', $matter->org_id)
            ->whereKey($relatedId)
            ->first();

        if (! $related instanceof Matter
            || AccessControl::effectiveMatterRole($actor, $related) === null
        ) {
            throw new AccessDeniedException(404);
        }

        // Canonical low → high by UUID string comparison.
        [$low, $high] = $matterId < $relatedId ? [$matterId, $relatedId] : [$relatedId, $matterId];

        return DB::transaction(function () use ($matter, $low, $high, $validated, $actor, $relatedId): MatterLink {
            $existing = MatterLink::query()
                ->where('matter_id', $low)
                ->where('related_matter_id', $high)
                ->first();

            if ($existing instanceof MatterLink) {
                return $existing;
            }

            $link = MatterLink::create([
                'org_id' => $matter->org_id,
                'matter_id' => $low,
                'related_matter_id' => $high,
                'link_type' => $validated['link_type'],
                'note' => $validated['note'] ?? null,
            ]);

            AuditLogger::log('matter.link.added', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'link_id' => (string) $link->getKey(),
                'related_matter_id' => $relatedId,
                'link_type' => $link->link_type,
            ], self::externalFlag($actor)), $matter);

            return $link->refresh();
        });
    }

    /**
     * Remove a related-matter link (hard delete — links carry no soft-delete
     * column; the removal itself is audited).
     *
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function removeLink(Matter $matter, MatterLink $link, User $actor): void
    {
        self::denyIfClosed($matter, $actor, 'matter.link.remove');

        DB::transaction(function () use ($matter, $link, $actor): void {
            $linkId = (string) $link->getKey();
            $link->delete();

            AuditLogger::log('matter.link.removed', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'link_id' => $linkId,
            ], self::externalFlag($actor)), $matter);
        });
    }

    // ── T-03: document log ─────────────────────────────────────────────

    /**
     * Append a received/sent register row (MAT-04). Core fields are immutable
     * after insert — corrections are new rows; only `annotations` is mutable
     * (see updateDocumentAnnotations()).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function logDocument(Matter $matter, array $attrs, User $actor): MatterDocumentLog
    {
        self::denyIfClosed($matter, $actor, 'matter.document.log');

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($attrs, [
            'direction' => ['required', 'string', Rule::in(MatterDocumentLog::DIRECTIONS)],
            'counterparty' => ['required', 'string', 'max:255'],
            'logged_at' => ['required', 'date'],
            'method' => ['required', 'string', Rule::in(MatterDocumentLog::METHODS)],
            'notes' => ['nullable', 'string'],
            'annotations' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($matter, $validated, $actor): MatterDocumentLog {
            $log = MatterDocumentLog::create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'direction' => $validated['direction'],
                'counterparty' => $validated['counterparty'],
                'logged_at' => $validated['logged_at'],
                'method' => $validated['method'],
                'notes' => $validated['notes'] ?? null,
                'annotations' => $validated['annotations'] ?? [],
            ]);

            AuditLogger::log('matter.document_logged', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'log_id' => (string) $log->getKey(),
                'direction' => $log->direction,
                'method' => $log->method,
            ], self::externalFlag($actor)), $matter);

            return $log->refresh();
        });
    }

    /**
     * Replace the annotations on a document-log row — the ONLY mutable field
     * (C-05). Core fields stay immutable; the HTTP layer rejects any other
     * key before this is called.
     *
     * @param  array<string, mixed>|null  $annotations
     *
     * @throws MatterStateException 409 {code:"matter_closed"}
     */
    public static function updateDocumentAnnotations(Matter $matter, MatterDocumentLog $log, ?array $annotations, User $actor): MatterDocumentLog
    {
        self::denyIfClosed($matter, $actor, 'matter.document.annotate');

        return DB::transaction(function () use ($matter, $log, $annotations, $actor): MatterDocumentLog {
            $log->forceFill(['annotations' => $annotations ?? []])->save();

            AuditLogger::log('matter.document_logged', $actor, array_merge([
                'actor_id' => (string) $actor->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'log_id' => (string) $log->getKey(),
                'annotations_updated' => true,
            ], self::externalFlag($actor)), $matter);

            return $log->refresh();
        });
    }

    // ── T-04: assignment ───────────────────────────────────────────────

    /**
     * Assignment roles accepted here — the contract's role names
     * (03-contract.md §Requests & validation), honored end-to-end via
     * 006-D11.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = ['owner', 'editor', 'viewer', 'outside_counsel'];

    /**
     * Matter roles that count as "owner" for the last-owner guard and the
     * assign privilege — the contract name and the legacy T-02 name are
     * equivalent (006-D11).
     *
     * @var list<string>
     */
    private const OWNER_LEVEL_ROLES = ['owner', 'matter_owner'];

    /**
     * Grant a user a matter role (MAT-07; 006-D05 rides on matter_grants).
     * Only the matter owner or an org admin may assign (403 otherwise,
     * audited as matter.assign.denied). Demoting the final owner-level
     * grant → 422 last_owner (audited as matter.assign.denied). Expiry is
     * honored by the authorization layer (expired grants deny).
     *
     * Idempotent: an existing (matter, user) row is re-roled/reactivated —
     * the partial unique index forbids a second row; history lives in the
     * audit log.
     *
     * @throws ValidationException 422 {code:"validation", details:{…}}
     * @throws AccessDeniedException 403 {code:"forbidden"}
     * @throws MatterStateException 422 {code:"last_owner"}
     *                              409 {code:"matter_closed"}
     */
    public static function assignUser(Matter $matter, string $userId, string $role, ?string $expiresAt, User $actor): MatterGrant
    {
        self::denyIfClosed($matter, $actor, 'matter.assign');
        self::assertCanAssign($matter, $actor);

        /** @var array{user_id: string, role: string, expires_at?: string|null} $validated */
        $validated = Validator::make(
            ['user_id' => $userId, 'role' => $role, 'expires_at' => $expiresAt],
            [
                'user_id' => [
                    'required',
                    'uuid',
                    Rule::exists('users', 'id')->where('org_id', $matter->org_id),
                ],
                'role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ]
        )->validate();

        try {
            return DB::transaction(function () use ($matter, $validated, $actor): MatterGrant {
                $grant = MatterGrant::query()
                    ->where('matter_id', $matter->getKey())
                    ->where('user_id', $validated['user_id'])
                    ->first();

                if ($grant instanceof MatterGrant
                    && self::isOwnerLevel($grant->role)
                    && ! self::isExpiredGrant($grant)
                    && ! self::isOwnerLevel($validated['role'])
                    && self::activeOwnerGrantCount($matter, (string) $grant->getKey()) === 0
                ) {
                    throw new MatterStateException(422, 'last_owner', [], 'matter.assign.denied');
                }

                if ($grant instanceof MatterGrant) {
                    $grant->forceFill([
                        'role' => $validated['role'],
                        'expires_at' => $validated['expires_at'] ?? null,
                        'granted_by' => $actor->getKey(),
                    ])->save();
                } else {
                    $grant = MatterGrant::create([
                        'org_id' => $matter->org_id,
                        'matter_id' => $matter->getKey(),
                        'user_id' => $validated['user_id'],
                        'role' => $validated['role'],
                        'expires_at' => $validated['expires_at'] ?? null,
                        'granted_by' => $actor->getKey(),
                    ]);
                }

                AuditLogger::log('matter.assigned', $actor, array_merge([
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'user_id' => $validated['user_id'],
                    'role' => $validated['role'],
                    'expires_at' => $validated['expires_at'] ?? null,
                ], self::externalFlag($actor)), $matter);

                return $grant->refresh();
            });
        } catch (MatterStateException $e) {
            self::logAssignDenial($matter, $actor, $userId, $e);

            throw $e;
        }
    }

    /**
     * Revoke a user's grant by setting expires_at — rows are never deleted
     * (001-D11 history preservation). Removing the final owner-level grant
     * → 422 last_owner.
     *
     * @throws AccessDeniedException 403 {code:"forbidden"}
     * @throws MatterStateException 422 {code:"last_owner"}
     *                              409 {code:"matter_closed"}
     */
    public static function unassignUser(Matter $matter, MatterGrant $grant, User $actor): MatterGrant
    {
        self::denyIfClosed($matter, $actor, 'matter.unassign');
        self::assertCanAssign($matter, $actor);

        try {
            return DB::transaction(function () use ($matter, $grant, $actor): MatterGrant {
                if (self::isOwnerLevel($grant->role)
                    && ! self::isExpiredGrant($grant)
                    && self::activeOwnerGrantCount($matter, (string) $grant->getKey()) === 0
                ) {
                    throw new MatterStateException(422, 'last_owner', [], 'matter.assign.denied');
                }

                $grant->forceFill(['expires_at' => now()])->save();

                AuditLogger::log('matter.unassigned', $actor, array_merge([
                    'actor_id' => (string) $actor->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'user_id' => $grant->user_id !== null ? (string) $grant->user_id : null,
                    'role' => $grant->role,
                ], self::externalFlag($actor)), $matter);

                return $grant->refresh();
            });
        } catch (MatterStateException $e) {
            self::logAssignDenial($matter, $actor, (string) $grant->user_id, $e);

            throw $e;
        }
    }

    // ── T-04: search ───────────────────────────────────────────────────

    /**
     * Permission-scoped matter search (MAT-15). Trigram similarity across
     * title / matter_number / client_name / party names, plus substring
     * fallback; structured filters AND-combined. Permission scoping happens
     * BEFORE pagination — ungranted matters are excluded entirely, never
     * merely hidden on later pages.
     *
     * @param  array{state?: string|null, type?: string|null, assignee?: string|null, client?: string|null, updated_since?: string|null}  $filters
     * @return LengthAwarePaginator<int, Matter>
     */
    public static function searchMatters(User $actor, ?string $query, array $filters): LengthAwarePaginator
    {
        $base = Matter::query()->accessibleBy($actor);

        $q = $query !== null ? trim($query) : '';

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

            $base->where(function ($where) use ($q, $like): void {
                $where->whereRaw('matters.title % ?', [$q])
                    ->orWhereRaw('matters.matter_number % ?', [$q])
                    ->orWhereRaw('matters.client_name % ?', [$q])
                    ->orWhereRaw('matters.title ILIKE ?', [$like])
                    ->orWhereRaw('matters.matter_number ILIKE ?', [$like])
                    ->orWhereRaw('matters.client_name ILIKE ?', [$like])
                    ->orWhereExists(function ($exists) use ($q, $like): void {
                        $exists->selectRaw('1')
                            ->from('matter_parties')
                            ->whereColumn('matter_parties.matter_id', 'matters.id')
                            ->whereNull('matter_parties.deleted_at')
                            ->where(function ($party) use ($q, $like): void {
                                $party->whereRaw('matter_parties.name % ?', [$q])
                                    ->orWhereRaw('matter_parties.name ILIKE ?', [$like]);
                            });
                    });
            });

            // Best trigram match first.
            $base->orderByRaw(
                'GREATEST(similarity(matters.title, ?), similarity(matters.matter_number, ?), similarity(matters.client_name, ?)) DESC',
                [$q, $q, $q]
            );
        }

        if (! empty($filters['state'])) {
            $base->where('matters.lifecycle_state', $filters['state']);
        }

        if (! empty($filters['type'])) {
            $base->where('matters.matter_type', $filters['type']);
        }

        if (! empty($filters['client'])) {
            $clientLike = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['client']).'%';
            $base->whereRaw('matters.client_name ILIKE ?', [$clientLike]);
        }

        if (! empty($filters['assignee'])) {
            $assigneeId = $filters['assignee'];
            $base->whereExists(function ($exists) use ($assigneeId): void {
                $exists->selectRaw('1')
                    ->from('matter_grants')
                    ->whereColumn('matter_grants.matter_id', 'matters.id')
                    ->where('matter_grants.user_id', $assigneeId)
                    ->where(function ($valid): void {
                        $valid->whereNull('matter_grants.expires_at')
                            ->orWhere('matter_grants.expires_at', '>', now());
                    });
            });
        }

        if (! empty($filters['updated_since'])) {
            $base->where('matters.updated_at', '>=', $filters['updated_since']);
        }

        return $base->orderByDesc('matters.updated_at')->paginate(25);
    }

    // ── T-04: timeline reads ───────────────────────────────────────────

    /**
     * Record the user's timeline read marker for unread-comment counts
     * (MAT-16). Internal telemetry — deliberately writes no audit row and
     * does not touch the matter.
     */
    public static function markTimelineRead(Matter $matter, User $user): void
    {
        MatterCommentRead::updateOrCreate(
            [
                'matter_id' => $matter->getKey(),
                'user_id' => $user->getKey(),
            ],
            [
                'org_id' => $matter->org_id,
                'last_read_at' => now(),
            ]
        );
    }

    // ── T-03/T-04 private helpers ──────────────────────────────────────

    /**
     * Contract: only the matter owner or an org admin may assign/unassign.
     *
     * @throws AccessDeniedException 403 {code:"forbidden"}
     */
    private static function assertCanAssign(Matter $matter, User $actor): void
    {
        $isOwner = AccessControl::roleSatisfies(
            AccessControl::effectiveMatterRole($actor, $matter),
            'matter_owner'
        );

        if ($actor->hasRole('org_admin') || $isOwner) {
            return;
        }

        AuditLogger::log('matter.assign.denied', $actor, array_merge([
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matter->getKey(),
            'attempted_action' => 'matter.assign',
            'denial_code' => 'forbidden',
        ], self::externalFlag($actor)), $matter);

        throw new AccessDeniedException(403, 'Only the matter owner or an org admin can assign users.');
    }

    /**
     * The mutation transaction rolled back — a last_owner denial is still
     * a security event and must be recorded (contract §Audit events).
     */
    private static function logAssignDenial(Matter $matter, User $actor, string $userId, MatterStateException $e): void
    {
        if ($e->denialAuditEvent === null) {
            return;
        }

        AuditLogger::log($e->denialAuditEvent, $actor, array_merge([
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matter->getKey(),
            'user_id' => $userId,
            'denial_code' => $e->errorCode,
        ], self::externalFlag($actor)), $matter);
    }

    private static function isOwnerLevel(?string $role): bool
    {
        return $role !== null && in_array($role, self::OWNER_LEVEL_ROLES, true);
    }

    private static function isExpiredGrant(MatterGrant $grant): bool
    {
        return $grant->expires_at !== null && $grant->expires_at->lte(now());
    }

    /**
     * Active (unexpired) owner-level grants on the matter, optionally
     * excluding one grant (the one being demoted/revoked).
     */
    private static function activeOwnerGrantCount(Matter $matter, ?string $excludeGrantId = null): int
    {
        $query = MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->whereIn('role', self::OWNER_LEVEL_ROLES)
            ->where(function ($valid): void {
                $valid->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($excludeGrantId !== null) {
            $query->where('id', '!=', $excludeGrantId);
        }

        return $query->count();
    }

    /**
     * C-09: actions by outside-counsel actors are audit-marked external.
     *
     * @return array<string, bool>
     */
    private static function externalFlag(User $actor): array
    {
        return $actor->hasRole('outside_counsel') ? ['external' => true] : [];
    }
}
