<?php

namespace App\Services\Calendaring;

use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\InstantiationBatch;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Recalculation (009 T-13, 009-D21): supersede-don't-rewrite.
 *
 * Preview recomputes the batch's template version from new inputs
 * via the DeadlineEngine and diffs by rule_key against the batch's
 * live (confirmed / at_risk) items. It persists nothing. Items whose
 * dates were manually overridden (date_overridden) are reported as
 * preserved and excluded from an apply unless the caller explicitly
 * passes reset_overrides.
 *
 * Apply runs in ONE transaction. Each changed, non-preserved item is
 * superseded through ItemService::supersede() — the old row keeps its
 * original date/provenance byte-identical, gains status=superseded
 * and the successor link, and the audit event
 * calendar.item.superseded is written by that service. The successor
 * carries the new date, a fresh engine trace, the new inputs, and
 * the same instantiation batch. The supersede hook cancels the old
 * item's pending reminder deliveries; the successor inherits the
 * old item's active reminder policy (a new policy v1 on the
 * successor), so the planner materializes its deliveries at the new
 * date. Checklist entries and dependency edges are carried onto the
 * successor, with edges between two superseded items re-pointed at
 * their successors. The batch's stored inputs are replaced last,
 * and the whole recalculation is audited as
 * calendar.template.recalculated.
 */
class RecalculationService
{
    /**
     * Diff the batch's live items against a recomputation from
     * $newInputs. Persists nothing.
     *
     * @param  array<string, mixed>  $newInputs
     * @return array{batch_id: string, inputs: array<string, mixed>,
     *               entries: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function preview(InstantiationBatch $batch, array $newInputs, bool $resetOverrides = false): array
    {
        $version = $batch->templateVersion;
        if ($version === null) {
            throw ValidationException::withMessages([
                'batch' => 'The instantiation batch has no template version to recalculate from.',
            ]);
        }

        $normalized = InstantiationService::normalizeInputs($newInputs, $version);

        try {
            $computed = app(DeadlineEngine::class)->compute($version, $normalized);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['inputs' => $e->getMessage()]);
        }

        /** @var array<string, array<string, mixed>> $tracesByKey */
        $tracesByKey = [];
        foreach ($computed['items'] as $trace) {
            $tracesByKey[(string) ($trace['rule_key'] ?? '')] = $trace;
        }

        $liveItems = CalendarItem::query()
            ->where('instantiation_batch_id', (string) $batch->getKey())
            ->whereIn('status', ['confirmed', 'at_risk'])
            ->orderBy('rule_key')
            ->orderBy('id')
            ->get();

        $entries = [];
        foreach ($liveItems as $item) {
            $ruleKey = (string) $item->rule_key;
            $trace = $tracesByKey[$ruleKey] ?? null;
            $oldDate = $item->due_date?->toDateString();
            $newDate = $trace !== null ? (string) $trace['result_date'] : null;
            $overridden = (bool) $item->date_overridden;
            $preserved = $overridden && ! $resetOverrides;

            $entries[] = [
                'item' => $item,
                'item_id' => (string) $item->getKey(),
                'rule_key' => $ruleKey,
                'old_date' => $oldDate,
                'new_date' => $newDate,
                'changed' => $newDate !== null && $newDate !== $oldDate,
                'date_overridden' => $overridden,
                'preserved' => $preserved,
                'trace' => $trace,
            ];
        }

        return [
            'batch_id' => (string) $batch->getKey(),
            'inputs' => $normalized,
            'entries' => $entries,
        ];
    }

    /**
     * Apply a recalculation: supersede every changed, non-preserved
     * live item and store the new inputs on the batch.
     *
     * @param  array<string, mixed>  $newInputs
     * @return array{batch: InstantiationBatch,
     *               successors: list<CalendarItem>,
     *               superseded: list<CalendarItem>,
     *               preserved: list<CalendarItem>}
     *
     * @throws ValidationException
     */
    public static function apply(InstantiationBatch $batch, array $newInputs, User $actor, bool $resetOverrides = false): array
    {
        $matter = $batch->matter;
        AccessControl::authorize($actor, 'edit', $matter);

        return DB::transaction(function () use ($batch, $newInputs, $actor, $resetOverrides, $matter): array {
            $preview = self::preview($batch, $newInputs, $resetOverrides);
            $normalized = $preview['inputs'];

            /** @var array<string, CalendarItem> $successorByOldId */
            $successorByOldId = [];
            /** @var list<CalendarItem> $superseded */
            $superseded = [];
            /** @var list<CalendarItem> $preserved */
            $preserved = [];

            foreach ($preview['entries'] as $entry) {
                /** @var CalendarItem $old */
                $old = $entry['item'];

                if (! $entry['changed']) {
                    continue;
                }

                if ($entry['preserved']) {
                    $preserved[] = $old;

                    continue;
                }

                /** @var array<string, mixed> $trace */
                $trace = $entry['trace'];

                $successor = ItemService::supersede($old, [
                    'due_date' => (string) $entry['new_date'],
                    'calc_trace' => $trace,
                    'inputs' => $normalized,
                    'instantiation_batch_id' => (string) $batch->getKey(),
                    'date_overridden' => false,
                    'cutoff_time' => self::cutoffFromTrace($trace, $old),
                ], $actor, 'Recalculation: instantiation inputs changed.');

                $successorByOldId[(string) $old->getKey()] = $successor;
                $superseded[] = $old->fresh() ?? $old;

                // Carry the active reminder policy onto the
                // successor as its v1; setPolicy reschedules, which
                // materializes the successor's deliveries at the new
                // date (the old item's pending deliveries were
                // already superseded by the supersede hook).
                $oldPolicy = ReminderPlanner::activePolicy($old);
                if ($oldPolicy !== null) {
                    ReminderPlanner::setPolicy($successor, $oldPolicy->rules, $actor);
                }

                // Carry checklist entries onto the successor.
                foreach ($old->checklistItems as $entryModel) {
                    ChecklistItem::create([
                        'item_id' => (string) $successor->getKey(),
                        'label' => $entryModel->label,
                        'required' => (bool) $entryModel->required,
                        'done' => (bool) $entryModel->done,
                        'done_by' => $entryModel->done_by,
                        'done_at' => $entryModel->done_at,
                        'position' => (int) $entryModel->position,
                    ]);
                }
            }

            // Re-point dependency edges that touched a superseded
            // item at its successor (edges between two superseded
            // items land successor-to-successor).
            foreach ($successorByOldId as $oldId => $successor) {
                $edges = TaskDependency::query()
                    ->where('predecessor_item_id', $oldId)
                    ->orWhere('successor_item_id', $oldId)
                    ->get();

                foreach ($edges as $edge) {
                    $predecessorId = (string) $edge->predecessor_item_id;
                    $successorId = (string) $edge->successor_item_id;
                    $newPredecessor = isset($successorByOldId[$predecessorId])
                        ? (string) $successorByOldId[$predecessorId]->getKey()
                        : $predecessorId;
                    $newSuccessor = isset($successorByOldId[$successorId])
                        ? (string) $successorByOldId[$successorId]->getKey()
                        : $successorId;

                    if ($newPredecessor === $predecessorId && $newSuccessor === $successorId) {
                        continue;
                    }

                    TaskDependency::firstOrCreate(
                        [
                            'predecessor_item_id' => $newPredecessor,
                            'successor_item_id' => $newSuccessor,
                        ],
                        [
                            'org_id' => (string) $edge->org_id,
                            'matter_id' => (string) $edge->matter_id,
                        ]
                    );
                }
            }

            $batch->update(['inputs' => $normalized]);

            AuditLogger::log('calendar.template.recalculated', $actor, [
                'batch_id' => (string) $batch->getKey(),
                'template_version_id' => (string) $batch->template_version_id,
                'superseded_count' => count($superseded),
                'preserved_count' => count($preserved),
                'reset_overrides' => $resetOverrides,
            ], $matter);

            return [
                'batch' => $batch->fresh() ?? $batch,
                'successors' => array_values($successorByOldId),
                'superseded' => $superseded,
                'preserved' => $preserved,
            ];
        });
    }

    /**
     * The successor's cutoff comes from the fresh trace when the rule
     * declares one; otherwise the old item's cutoff stands (the
     * supersede copy carries it, and returning it here is a no-op).
     *
     * @param  array<string, mixed>  $trace
     */
    private static function cutoffFromTrace(array $trace, CalendarItem $old): ?string
    {
        $cutoff = $trace['cutoff'] ?? null;
        if (is_string($cutoff) && $cutoff !== '') {
            return strlen($cutoff) === 5 ? $cutoff.':00' : $cutoff;
        }

        return $old->cutoff_time !== null ? substr((string) $old->cutoff_time, 0, 8) : null;
    }
}
