<?php

namespace App\Services;

use App\Exceptions\RetentionException;
use App\Models\DispositionQueue;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentTombstone;
use App\Models\Matter;
use App\Models\RetentionFlag;
use App\Models\RetentionPolicy;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention policies, flagging, and the disposition queue (007 T-09,
 * DOC-27/28/29).
 *
 * Policies are versioned: an edit inserts a new row with the same
 * (org_id, name) and version+1; activating a version marks the previous
 * active row 'superseded'. The nightly evaluation (retention:evaluate)
 * only reads status='active' rows.
 *
 * Disposition outcomes:
 * - destroy: dual control — two DISTINCT approvers. On the second
 *   approval the document's blobs+versions are destroyed through
 *   DocumentFilingService::hardDelete() (the single sanctioned
 *   007-D07 GUC-bypass path; holds re-checked → 423) and a permanent
 *   DocumentTombstone is retained.
 * - extend: single org_admin decision; sets retention_extended_until and
 *   clears flags (the nightly run skips extended documents).
 * - archive: single org_admin decision; blob bytes move to the cold
 *   prefix, blobs are flagged archived (preview disabled), the document
 *   status becomes 'archived', and external shares are revoked.
 *
 * Audit events (00-brief.md): document.retention.flagged,
 * document.disposition.decided, document.destroyed; denials as
 * document.destroy.denied (incl. hold-blocked 423).
 */
class RetentionService
{
    // ── Policies ────────────────────────────────────────────────

    /**
     * Create a draft policy (version 1).
     *
     * @param  array<string, mixed>  $data  name, category, matter_type?,
     *                                      trigger, disposition, legal_basis, period_amount, period_unit,
     *                                      fixed_date?
     *
     * @throws RetentionException 422 on invalid input
     */
    public static function createPolicy(User $actor, array $data): RetentionPolicy
    {
        $clean = self::validatePolicyInput($data);

        return DB::transaction(function () use ($actor, $clean): RetentionPolicy {
            // retention_period is a native Postgres interval (NOT NULL):
            // it must ride along on the INSERT. The match arms are
            // literal strings (unit validated against the enum), so this
            // stays injection-safe without concatenation.
            // Placeholder: the column is NOT NULL, so the INSERT carries a
            // literal; the validated value lands via the parameterized
            // UPDATE below (never interpolated into SQL).
            $intervalPlaceholder = DB::raw("INTERVAL '1 day'");
            $intervalValue = "{$clean['period_amount']} {$clean['period_unit']}";
            $policy = new RetentionPolicy([

                'org_id' => $actor->org_id,
                'name' => $clean['name'],
                'category' => $clean['category'],
                'matter_type' => $clean['matter_type'],
                'trigger' => $clean['trigger'],
                'disposition' => $clean['disposition'],
                'legal_basis' => $clean['legal_basis'],
                'version' => 1,
                'status' => 'draft',
                'fixed_date' => $clean['fixed_date'],
            ]);
            $policy->forceFill(['retention_period' => $intervalPlaceholder]);
            $policy->save();
            DB::update(
                'UPDATE retention_policies SET retention_period = CAST(? AS interval) WHERE id = ?',
                [$intervalValue, (string) $policy->getKey()]
            );

            AuditLogger::log('retention.policy.created', $actor, [
                'policy_id' => (string) $policy->getKey(),
                'name' => $policy->name,
                'version' => 1,
            ], null, explicitOrgId: (string) $actor->org_id);

            return $policy->refresh();
        });
    }

    /**
     * Edit a policy = new draft version; the old row is retained untouched.
     *
     * @param  array<string, mixed>  $data  same shape as createPolicy
     *
     * @throws RetentionException 422 on invalid input
     */
    public static function newPolicyVersion(User $actor, RetentionPolicy $policy, array $data): RetentionPolicy
    {
        self::assertSameOrg($actor, (string) $policy->org_id);

        $clean = self::validatePolicyInput($data);

        $nextVersion = (int) RetentionPolicy::query()
            ->where('org_id', $policy->org_id)
            ->where('name', $policy->name)
            ->max('version') + 1;

        return DB::transaction(function () use ($actor, $policy, $clean, $nextVersion): RetentionPolicy {
            // retention_period is a native Postgres interval (NOT NULL):
            // it must ride along on the INSERT. The match arms are
            // literal strings (unit validated against the enum), so this
            // stays injection-safe without concatenation.
            // Placeholder: the column is NOT NULL, so the INSERT carries a
            // literal; the validated value lands via the parameterized
            // UPDATE below (never interpolated into SQL).
            $intervalPlaceholder = DB::raw("INTERVAL '1 day'");
            $intervalValue = "{$clean['period_amount']} {$clean['period_unit']}";
            $version = new RetentionPolicy([

                'org_id' => $policy->org_id,
                'name' => $policy->name,
                'category' => $clean['category'],
                'matter_type' => $clean['matter_type'],
                'trigger' => $clean['trigger'],
                'disposition' => $clean['disposition'],
                'legal_basis' => $clean['legal_basis'],
                'version' => $nextVersion,
                'status' => 'draft',
                'fixed_date' => $clean['fixed_date'],
            ]);
            $version->forceFill(['retention_period' => $intervalPlaceholder]);
            $version->save();
            DB::update(
                'UPDATE retention_policies SET retention_period = CAST(? AS interval) WHERE id = ?',
                [$intervalValue, (string) $version->getKey()]
            );

            AuditLogger::log('retention.policy.created', $actor, [
                'policy_id' => (string) $version->getKey(),
                'name' => $version->name,
                'version' => $nextVersion,
                'supersedes_version' => $policy->version,
            ], null, explicitOrgId: (string) $actor->org_id);

            return $version->refresh();
        });
    }

    /**
     * Activate a draft version. The previously active version of the same
     * (org, name) line becomes 'superseded' — retained, never deleted.
     *
     * @throws RetentionException 409 not_a_draft | 422 fixed_date_required
     */
    public static function activatePolicy(User $actor, RetentionPolicy $policy): RetentionPolicy
    {
        self::assertSameOrg($actor, (string) $policy->org_id);

        if ($policy->status !== 'draft') {
            throw new RetentionException(409, 'not_a_draft', [
                'policy_id' => (string) $policy->getKey(),
            ]);
        }

        if ($policy->trigger === 'fixed_date' && $policy->fixed_date === null) {
            throw new RetentionException(422, 'fixed_date_required');
        }

        return DB::transaction(function () use ($actor, $policy): RetentionPolicy {
            RetentionPolicy::query()
                ->where('org_id', $policy->org_id)
                ->where('name', $policy->name)
                ->where('status', 'active')
                ->where('id', '!=', $policy->getKey())
                ->update(['status' => 'superseded', 'updated_at' => now()]);

            $policy->forceFill(['status' => 'active'])->save();

            AuditLogger::log('retention.policy.activated', $actor, [
                'policy_id' => (string) $policy->getKey(),
                'name' => $policy->name,
                'version' => $policy->version,
            ], null, explicitOrgId: (string) $actor->org_id);

            return $policy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, category: string, matter_type: string|null, trigger: string, disposition: string, legal_basis: string, period_amount: int, period_unit: string, fixed_date: string|null}
     *
     * @throws RetentionException 422
     */
    private static function validatePolicyInput(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $trigger = (string) ($data['trigger'] ?? '');
        $disposition = (string) ($data['disposition'] ?? '');
        $legalBasis = trim((string) ($data['legal_basis'] ?? ''));
        $matterType = $data['matter_type'] ?? null;
        $matterType = $matterType === null || $matterType === '' ? null : trim((string) $matterType);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new RetentionException(422, 'invalid_name');
        }

        if ($category === '' || mb_strlen($category) > 120) {
            throw new RetentionException(422, 'invalid_category');
        }

        if (! in_array($trigger, RetentionPolicy::TRIGGERS, true)) {
            throw new RetentionException(422, 'invalid_trigger');
        }

        if (! in_array($disposition, RetentionPolicy::DISPOSITIONS, true)) {
            throw new RetentionException(422, 'invalid_disposition');
        }

        if ($legalBasis === '') {
            throw new RetentionException(422, 'legal_basis_required');
        }

        $amount = (int) ($data['period_amount'] ?? 0);
        $unit = (string) ($data['period_unit'] ?? '');

        if ($amount < 1 || ! in_array($unit, RetentionPolicy::PERIOD_UNITS, true)) {
            throw new RetentionException(422, 'invalid_retention_period');
        }

        $fixedDate = null;

        if ($trigger === 'fixed_date') {
            $raw = trim((string) ($data['fixed_date'] ?? ''));

            if ($raw === '' || strtotime($raw) === false) {
                throw new RetentionException(422, 'fixed_date_required');
            }

            $fixedDate = $raw;
        }

        return [
            'name' => $name,
            'category' => $category,
            'matter_type' => $matterType,
            'trigger' => $trigger,
            'disposition' => $disposition,
            'legal_basis' => $legalBasis,
            'period_amount' => $amount,
            'period_unit' => $unit,
            'fixed_date' => $fixedDate,
        ];
    }

    // ── Simulator ───────────────────────────────────────────────

    /**
     * Read-only preview of which existing documents a (draft or active)
     * policy would affect. No side effects: no flags, no queue rows.
     *
     * @return array{count: int, documents: list<array{id: string, title: string, matter_number: string, category: string|null, base_at: string|null, due_at: string|null}>}
     */
    public static function simulate(RetentionPolicy $policy): array
    {
        $rows = self::matchingDocumentsQuery($policy)->limit(25)->get();

        // Raw-selected timestamps come back as strings (no model casts
        // apply to selectRaw aliases) — normalize defensively.
        $iso = fn (mixed $value): ?string => $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : ($value === null ? null : (string) $value);

        $documents = $rows->map(function (Document $row) use ($iso): array {
            return [
                'id' => (string) $row->getKey(),
                // Leak sentinels: the simulator lives behind org_admin only;
                // titles never leave that boundary.
                'title' => (string) $row->title,
                'matter_number' => (string) ($row->getAttribute('matter_number') ?? ''),
                'category' => $row->getAttribute('category') === null ? null : (string) $row->getAttribute('category'),
                'base_at' => $iso($row->getAttribute('base_at')),
                'due_at' => $iso($row->getAttribute('due_at')),
            ];
        });

        return [
            'count' => self::matchingDocumentsQuery($policy)->count(),
            'documents' => array_values($documents->all()),
        ];
    }

    /**
     * Documents the policy would flag: same-org, not trashed, not
     * archived, category match, optional matter-type match, no live
     * extension, no existing flag, and base date + retention period
     * already reached.
     *
     * @return Builder<Document>
     */
    protected static function matchingDocumentsQuery(RetentionPolicy $policy): Builder
    {
        $policyId = (string) $policy->getKey();

        $query = Document::query()
            ->select('documents.*')
            ->join('matters', 'matters.id', '=', 'documents.matter_id')
            ->addSelect('matters.matter_number as matter_number')
            ->where('documents.org_id', $policy->org_id)
            ->whereNull('documents.deleted_at')
            ->where('documents.status', '!=', 'archived')
            ->where('documents.category', $policy->category)
            ->when(
                $policy->matter_type !== null,
                fn (Builder $q): Builder => $q->where('matters.matter_type', $policy->matter_type)
            )
            ->where(function (Builder $q): void {
                $q->whereNull('documents.retention_extended_until')
                    ->orWhere('documents.retention_extended_until', '<=', now());
            })
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('retention_flags')
                    ->whereColumn('retention_flags.document_id', 'documents.id');
            });

        $interval = '(SELECT retention_period FROM retention_policies WHERE retention_policies.id = ?)';

        switch ($policy->trigger) {
            case 'document_date':
                $base = 'documents.created_at';
                $query->selectRaw("({$base}) AS base_at")
                    ->selectRaw("({$base} + {$interval}) AS due_at", [$policyId])
                    ->whereRaw("{$base} + {$interval} <= now()", [$policyId]);
                break;

            case 'matter_close':
                $base = 'matters.closed_at';
                $query->selectRaw("({$base}) AS base_at")
                    ->selectRaw("({$base} + {$interval}) AS due_at", [$policyId])
                    ->whereNotNull('matters.closed_at')
                    ->whereRaw("{$base} + {$interval} <= now()", [$policyId]);
                break;

            case 'fixed_date':
                if ($policy->fixed_date === null) {
                    // A fixed_date policy without its anchor matches
                    // nothing rather than everything.
                    return $query->whereRaw('1 = 0');
                }
                $anchor = $policy->fixed_date->toIso8601String();

                $query->selectRaw('? AS base_at', [$anchor])
                    ->selectRaw("(CAST(? AS timestamptz) + {$interval}) AS due_at", [$anchor, $policyId])
                    ->whereRaw("CAST(? AS timestamptz) + {$interval} <= now()", [$anchor, $policyId]);
                break;

            default:
                return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    // ── Nightly evaluation ──────────────────────────────────────

    /**
     * Flag documents at retention thresholds and auto-queue destroy /
     * archive dispositions. Idempotent: documents with an existing flag
     * or an open queue entry are skipped; held documents are flagged but
     * never auto-queued (the hold wins).
     *
     * @return array{policies: int, flagged: int, queued: int}
     */
    public static function evaluateNightly(): array
    {
        $policies = 0;
        $flagged = 0;
        $queued = 0;

        foreach (RetentionPolicy::query()->active()->cursor() as $policy) {
            $policies++;

            foreach (self::matchingDocumentsQuery($policy)->cursor() as $document) {
                /** @var Document $document */
                self::flagDocument(
                    $document,
                    $policy,
                    "Retention threshold reached — policy '{$policy->name}' v{$policy->version} ({$policy->disposition}).",
                    null
                );
                $flagged++;

                if (! in_array($policy->disposition, ['destroy', 'archive'], true)) {
                    continue;
                }

                if (LegalHoldService::holdsBlocking($document)) {
                    continue;
                }

                $openEntry = DispositionQueue::query()
                    ->where('document_id', $document->getKey())
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists();

                if ($openEntry) {
                    continue;
                }

                DispositionQueue::create([
                    'document_id' => $document->getKey(),
                    'action' => $policy->disposition,
                    'requested_by' => null,
                    'status' => 'pending',
                ]);
                $queued++;
            }
        }

        return ['policies' => $policies, 'flagged' => $flagged, 'queued' => $queued];
    }

    // ── Flagging ────────────────────────────────────────────────

    /**
     * Flag a document (manual when $policy is null). Idempotent per
     * (document, policy): re-flagging returns the existing row.
     */
    public static function flagDocument(
        Document $document,
        ?RetentionPolicy $policy,
        string $reason,
        ?User $actor
    ): RetentionFlag {
        if ($policy !== null && (string) $policy->org_id !== (string) $document->org_id) {
            throw new RetentionException(422, 'policy_org_mismatch');
        }

        return DB::transaction(function () use ($document, $policy, $reason, $actor): RetentionFlag {
            $existing = RetentionFlag::query()
                ->where('document_id', $document->getKey())
                ->when(
                    $policy === null,
                    fn (Builder $q): Builder => $q->whereNull('policy_id'),
                    fn (Builder $q): Builder => $q->where('policy_id', $policy->getKey())
                )
                ->first();

            if ($existing instanceof RetentionFlag) {
                return $existing;
            }

            /** @var RetentionFlag $flag */
            $flag = RetentionFlag::create([
                'document_id' => $document->getKey(),
                'policy_id' => $policy?->getKey(),
                'reason' => $reason,
                'flagged_by' => $actor?->getKey(),
                'flagged_at' => now(),
            ]);

            $document->forceFill(['retention_flagged_at' => now()])->save();

            $matter = $document->matter;

            AuditLogger::log('document.retention.flagged', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $document->matter_id,
                'policy_id' => $policy === null ? null : (string) $policy->getKey(),
                'flag_id' => (string) $flag->getKey(),
            ], $matter instanceof Matter ? $matter : null, explicitOrgId: (string) $document->org_id);

            return $flag;
        });
    }

    // ── Disposition queue ───────────────────────────────────────

    /**
     * Open a disposition queue entry (org_admin).
     *
     * @throws RetentionException 422 invalid_action | reason_required | extend_date_required | extend_date_not_future
     */
    public static function requestDisposition(
        User $actor,
        Document $document,
        string $action,
        ?string $newRetentionDate,
        string $reason
    ): DispositionQueue {
        self::assertSameOrg($actor, (string) $document->org_id);

        if (! in_array($action, DispositionQueue::ACTIONS, true)) {
            throw new RetentionException(422, 'invalid_action');
        }

        if (trim($reason) === '') {
            throw new RetentionException(422, 'reason_required');
        }

        $extendDate = null;

        if ($action === 'extend') {
            $raw = trim((string) $newRetentionDate);

            if ($raw === '' || ($extendDate = date_create($raw)) === false) {
                throw new RetentionException(422, 'extend_date_required');
            }

            if ($extendDate <= new \DateTimeImmutable) {
                throw new RetentionException(422, 'extend_date_not_future');
            }
        }

        /** @var DispositionQueue $entry */
        $entry = DispositionQueue::create([
            'document_id' => $document->getKey(),
            'action' => $action,
            'requested_by' => $actor->getKey(),
            'status' => 'pending',
            'new_retention_date' => $extendDate?->format('Y-m-d H:i:sP'),
        ]);

        return $entry;
    }

    /**
     * Decide a queue entry. 'approve' records an approval; destroy needs
     * two DISTINCT approvers before it executes (same user twice → 409).
     * extend/archive execute on a single org_admin approval.
     *
     * @throws RetentionException 409 not_pending | duplicate_approval | 423 hold_locked
     */
    public static function approveDisposition(User $actor, DispositionQueue $entry, ?string $note = null): DispositionQueue
    {
        self::assertSameOrg($actor, (string) $entry->document->org_id);

        if (! $entry->isPending()) {
            throw new RetentionException(409, 'not_pending', [
                'queue_id' => (string) $entry->getKey(),
            ]);
        }

        if ($entry->action === 'destroy') {
            if (in_array((string) $actor->getKey(), $entry->approverIds(), true)) {
                // Dual control: the same user twice does not count.
                throw new RetentionException(409, 'duplicate_approval', [
                    'queue_id' => (string) $entry->getKey(),
                ]);
            }

            /** @var list<array{approver_id: string, decided_at: string}> $approvals */
            $approvals = $entry->approvals ?? [];
            $entry->recordApproval($actor, $approvals);
            $entry->refresh();

            self::auditDispositionDecided($actor, $entry, 'approved', $note);

            if ($entry->approvalCount() >= 2) {
                self::executeDestroy($actor, $entry);

                // The row is gone — hardDelete's cleanup removes the
                // executed queue entry; the tombstone and the audit trail
                // preserve the record. Do not refresh() a deleted row.
                return $entry;
            }

            return $entry->refresh();
        }

        // extend / archive: single org_admin decision executes immediately.
        self::auditDispositionDecided($actor, $entry, 'approved', $note);

        if ($entry->action === 'extend') {
            self::executeExtend($actor, $entry);
        } else {
            self::executeArchive($actor, $entry);
        }

        return $entry->refresh();
    }

    /**
     * Reject a pending entry with a reason.
     *
     * @throws RetentionException 409 not_pending | 422 reason_required
     */
    public static function rejectDisposition(User $actor, DispositionQueue $entry, string $reason): DispositionQueue
    {
        self::assertSameOrg($actor, (string) $entry->document->org_id);

        if (! $entry->isPending()) {
            throw new RetentionException(409, 'not_pending', [
                'queue_id' => (string) $entry->getKey(),
            ]);
        }

        if (trim($reason) === '') {
            throw new RetentionException(422, 'reason_required');
        }

        $entry->forceFill([
            'status' => 'rejected',
            'decided_at' => now(),
        ])->save();

        self::auditDispositionDecided($actor, $entry->refresh(), 'rejected', $reason);

        return $entry->refresh();
    }

    /**
     * @throws RetentionException 423 hold_locked | 409 document_gone
     */
    protected static function executeDestroy(User $actor, DispositionQueue $entry): void
    {
        $document = $entry->document()->withTrashed()->first();

        if (! $document instanceof Document) {
            throw new RetentionException(409, 'document_gone');
        }

        $matter = $document->matter;

        if (! $matter instanceof Matter) {
            throw new RetentionException(409, 'document_gone');
        }

        if (LegalHoldService::holdsBlocking($document)) {
            AuditLogger::log('document.destroy.denied', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'queue_id' => (string) $entry->getKey(),
                'reason' => 'legal_hold',
            ], $matter, explicitOrgId: (string) $matter->org_id);

            throw new RetentionException(423, 'hold_locked', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        DB::transaction(function () use ($actor, $entry, $document, $matter): void {
            $blob = $document->currentVersion?->blob;

            DocumentTombstone::create([
                'org_id' => $document->org_id,
                'matter_id' => $matter->getKey(),
                'title' => $document->title,
                'sha256' => $blob instanceof DocumentBlob ? $blob->sha256 : '',
                'destroyed_at' => now(),
                'destroyed_by' => $actor->getKey(),
                'approvals' => $entry->approvals ?? [],
            ]);

            $entry->forceFill([
                'status' => 'executed',
                'decided_at' => now(),
            ])->save();

            self::auditDispositionDecided($actor, $entry->refresh(), 'executed');

            // The single sanctioned destruction path (007-D07): re-checks
            // holds, purges versions through the GUC bypass, removes blob
            // bytes at refcount 0, and writes document.destroyed. Its
            // cleanup also removes this queue row and the flag rows — the
            // audit trail and the tombstone preserve the record.
            DocumentFilingService::hardDelete($matter, $document, $actor);
        });
    }

    /**
     * @throws RetentionException 423 hold_locked
     */
    protected static function executeArchive(User $actor, DispositionQueue $entry): void
    {
        $document = $entry->document;

        if (! $document instanceof Document) {
            throw new RetentionException(409, 'document_gone');
        }

        $matter = $document->matter;

        if (LegalHoldService::holdsBlocking($document)) {
            AuditLogger::log('document.destroy.denied', $actor, [
                'document_id' => (string) $document->getKey(),
                'matter_id' => (string) $document->matter_id,
                'queue_id' => (string) $entry->getKey(),
                'reason' => 'legal_hold',
            ], $matter instanceof Matter ? $matter : null, explicitOrgId: (string) $document->org_id);

            throw new RetentionException(423, 'hold_locked', [
                'document_id' => (string) $document->getKey(),
            ]);
        }

        DB::transaction(function () use ($actor, $entry, $document): void {
            foreach ($document->versions()->with('blob')->cursor() as $version) {
                $blob = $version->blob;

                if ($blob instanceof DocumentBlob) {
                    self::archiveBlob($blob);
                }
            }

            $document->forceFill([
                'status' => 'archived',
                'retention_flagged_at' => null,
            ])->save();

            RetentionFlag::query()
                ->where('document_id', $document->getKey())
                ->delete();

            // External shares die with the archive — cold storage is not
            // a serving tier.
            DB::table('document_shares')
                ->where('document_id', $document->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $entry->forceFill([
                'status' => 'executed',
                'decided_at' => now(),
            ])->save();

            self::auditDispositionDecided($actor, $entry->refresh(), 'executed');
        });
    }

    protected static function executeExtend(User $actor, DispositionQueue $entry): void
    {
        $document = $entry->document;

        if (! $document instanceof Document) {
            throw new RetentionException(409, 'document_gone');
        }

        DB::transaction(function () use ($actor, $entry, $document): void {
            $document->forceFill([
                'retention_extended_until' => $entry->new_retention_date,
                'retention_flagged_at' => null,
            ])->save();

            RetentionFlag::query()
                ->where('document_id', $document->getKey())
                ->delete();

            $entry->forceFill([
                'status' => 'executed',
                'decided_at' => now(),
            ])->save();

            self::auditDispositionDecided($actor, $entry->refresh(), 'executed');
        });
    }

    /**
     * Move one blob's bytes to the cold-storage prefix and flag the row.
     * Preview is disabled for archived blobs (the document status flips
     * to 'archived'); bytes remain retrievable through the store.
     *
     * The physical move lives here — not in LocalDocumentStore — because
     * T-03 owns that file on this branch; it moves into
     * DocumentStore::archive() at the T-10 freeze.
     *
     * @throws RetentionException 500 archive_failed
     */
    protected static function archiveBlob(DocumentBlob $blob): void
    {
        $blob = $blob->fresh() ?? $blob;

        if ($blob->archived) {
            return;
        }

        $sha = (string) $blob->sha256;
        $coldPath = trim((string) config('document.cold_prefix', 'cold'), '/')
            .'/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;

        $disk = Storage::disk((string) config('document.disk', 'documents'));

        DB::transaction(function () use ($blob, $disk, $coldPath): void {
            if (! $disk->move((string) $blob->storage_path, $coldPath)) {
                throw new RetentionException(500, 'archive_failed', [
                    'blob_id' => (string) $blob->getKey(),
                ]);
            }

            $blob->forceFill([
                'storage_path' => $coldPath,
                'archived' => true,
            ])->save();
        });
    }

    private static function auditDispositionDecided(
        User $actor,
        DispositionQueue $entry,
        string $decision,
        ?string $note = null
    ): void {
        $document = $entry->document;
        $matter = $document?->matter;

        AuditLogger::log('document.disposition.decided', $actor, array_filter([
            'queue_id' => (string) $entry->getKey(),
            'document_id' => $document === null ? null : (string) $document->getKey(),
            'matter_id' => $document === null ? null : (string) $document->matter_id,
            'action' => $entry->action,
            'decision' => $decision,
            'approvals' => $entry->approvalCount(),
            'note' => $note,
        ], fn ($value): bool => $value !== null), $matter instanceof Matter ? $matter : null, explicitOrgId: $document === null ? (string) $actor->org_id : (string) $document->org_id);
    }

    /**
     * @throws RetentionException 403 cross_org
     */
    private static function assertSameOrg(User $actor, string $orgId): void
    {
        if ((string) $actor->org_id !== $orgId) {
            throw new RetentionException(403, 'forbidden', ['reason' => 'cross_org']);
        }
    }
}
