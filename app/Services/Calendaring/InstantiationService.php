<?php

namespace App\Services\Calendaring;

use App\Exceptions\AccessDeniedException;
use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\InstantiationBatch;
use App\Models\Matter;
use App\Models\ReminderPolicy;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Template instantiation (009 T-12, 009-D20).
 *
 * Preview is a pure DeadlineEngine computation over a PUBLISHED
 * template version: it persists nothing — no batch, no items, no
 * audit row. Confirm is the only writer and runs in ONE transaction:
 * batch row, generated items (status confirmed, source=template,
 * full engine trace as provenance), dependency edges, checklist
 * entries, and per-item reminder policies declared by the template
 * rules. Any failure rolls the whole graph back.
 *
 * The server ALWAYS recomputes at confirm from (version, inputs);
 * client-supplied dates are never accepted. Confirm is idempotent
 * on (matter, idempotency_key): a repeat confirm returns the
 * existing batch unchanged.
 *
 * Inputs shape: {trigger_date: Y-m-d, <trigger key>: Y-m-d (alias,
 * e.g. service_date), timezone?: IANA, triggers?: {key: Y-m-d}}.
 * normalizeInputs() folds the aliases into the engine's canonical
 * trigger_date form; the normalized array is what gets stored on
 * the batch and on every generated item.
 */
class InstantiationService
{
    /**
     * Fold caller inputs into the engine's canonical shape.
     *
     * The trigger key is read from the version's own trigger
     * declarations when available (falling back to `service_date`,
     * the engine's default); a date supplied under that key — flat
     * or inside `triggers` — becomes `trigger_date`.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public static function normalizeInputs(array $inputs, ?DeadlineTemplateVersion $version = null): array
    {
        $normalized = $inputs;

        $triggerKey = 'service_date';
        if (isset($normalized['trigger_key']) && is_string($normalized['trigger_key']) && $normalized['trigger_key'] !== '') {
            $triggerKey = $normalized['trigger_key'];
        } elseif ($version !== null) {
            $rules = $version->rules;
            if (isset($rules['triggers']) && is_array($rules['triggers'])) {
                foreach ($rules['triggers'] as $trigger) {
                    if (is_array($trigger) && isset($trigger['key']) && is_string($trigger['key']) && $trigger['key'] !== '') {
                        $triggerKey = $trigger['key'];
                        break;
                    }
                }
            }
        }
        $normalized['trigger_key'] = $triggerKey;

        $triggerDate = null;
        if (isset($normalized['trigger_date']) && is_string($normalized['trigger_date']) && $normalized['trigger_date'] !== '') {
            $triggerDate = $normalized['trigger_date'];
        } elseif (isset($normalized[$triggerKey]) && is_string($normalized[$triggerKey]) && $normalized[$triggerKey] !== '') {
            $triggerDate = $normalized[$triggerKey];
        } elseif (isset($normalized['triggers']) && is_array($normalized['triggers'])
            && isset($normalized['triggers'][$triggerKey]) && is_string($normalized['triggers'][$triggerKey])) {
            $triggerDate = $normalized['triggers'][$triggerKey];
        }

        if ($triggerDate !== null) {
            $normalized['trigger_date'] = $triggerDate;
            $normalized[$triggerKey] = $triggerDate;
        }

        if (! isset($normalized['timezone']) || ! is_string($normalized['timezone']) || $normalized['timezone'] === '') {
            $normalized['timezone'] = 'UTC';
        }

        return $normalized;
    }

    /**
     * Compute the graph for a published version without persisting
     * anything.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{template_version_id: string, template_version: mixed,
     *               trigger_date: string, inputs: array<string, mixed>,
     *               items: list<array<string, mixed>>,
     *               warnings: list<string>}
     *
     * @throws ValidationException when the version is not published
     *                             or the inputs cannot be computed
     */
    public static function preview(Matter $matter, DeadlineTemplateVersion $version, array $inputs): array
    {
        self::assertVersionUsable($matter, $version);

        $normalized = self::normalizeInputs($inputs, $version);
        $computed = self::compute($version, $normalized);

        return [
            'template_version_id' => (string) $version->getKey(),
            'template_version' => $computed['template_version'],
            'trigger_date' => $computed['trigger_date'],
            'inputs' => $normalized,
            'items' => $computed['items'],
            'warnings' => [],
        ];
    }

    /**
     * Confirm an instantiation: one transaction, idempotent on
     * (matter, idempotency key).
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws ValidationException
     */
    public static function confirm(Matter $matter, DeadlineTemplateVersion $version, array $inputs, string $idempotencyKey, User $actor): InstantiationBatch
    {
        AccessControl::authorize($actor, 'edit', $matter);

        if (trim($idempotencyKey) === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required to confirm an instantiation.',
            ]);
        }

        // Idempotency first: a duplicate confirm returns the existing
        // batch unchanged, even if the version has since moved on.
        $existing = InstantiationBatch::query()
            ->where('matter_id', (string) $matter->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        self::assertVersionUsable($matter, $version);

        $normalized = self::normalizeInputs($inputs, $version);

        return DB::transaction(function () use ($matter, $version, $normalized, $idempotencyKey, $actor): InstantiationBatch {
            // Re-check inside the transaction: a concurrent confirm
            // with the same key wins and this one folds into it (the
            // unique pair would otherwise abort the transaction).
            $raced = InstantiationBatch::query()
                ->where('matter_id', (string) $matter->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($raced !== null) {
                return $raced;
            }

            // The server always recomputes from (version, inputs).
            $computed = self::compute($version, $normalized);
            $definitions = self::itemDefinitions($version);

            $batch = InstantiationBatch::create([
                'org_id' => (string) $matter->org_id,
                'matter_id' => (string) $matter->getKey(),
                'template_version_id' => (string) $version->getKey(),
                'idempotency_key' => $idempotencyKey,
                'inputs' => $normalized,
                'created_by' => (string) $actor->getKey(),
            ]);

            $template = $version->template;
            $timezone = (string) $normalized['timezone'];

            /** @var array<string, CalendarItem> $createdByKey */
            $createdByKey = [];

            foreach ($computed['items'] as $trace) {
                $ruleKey = (string) ($trace['rule_key'] ?? '');
                $definition = $definitions[$ruleKey] ?? [];
                $kind = (string) ($trace['kind'] ?? ($definition['type'] ?? 'deadline'));
                if (! in_array($kind, CalendarItem::TYPES, true)) {
                    $kind = 'deadline';
                }

                $cutoff = $trace['cutoff'] ?? null;
                $cutoff = is_string($cutoff) && $cutoff !== '' ? (strlen($cutoff) === 5 ? $cutoff.':00' : $cutoff) : null;

                $item = CalendarItem::create([
                    'org_id' => (string) $matter->org_id,
                    'matter_id' => (string) $matter->getKey(),
                    'type' => $kind,
                    'title' => (string) ($trace['title'] ?? $ruleKey),
                    'visibility' => 'matter',
                    'status' => 'confirmed',
                    'task_status' => $kind === 'task' ? 'not_started' : null,
                    'classification' => isset($definition['classification']) && is_string($definition['classification'])
                        ? $definition['classification']
                        : (isset($trace['criticality']) && is_string($trace['criticality']) ? $trace['criticality'] : null),
                    'jurisdiction' => $template?->jurisdiction_label,
                    'due_date' => (string) $trace['result_date'],
                    'cutoff_time' => $cutoff,
                    'all_day' => false,
                    'timezone' => $timezone,
                    'version' => 1,
                    'source' => 'template',
                    'template_version_id' => (string) $version->getKey(),
                    'instantiation_batch_id' => (string) $batch->getKey(),
                    'date_overridden' => false,
                    'rule_key' => $ruleKey,
                    'inputs' => $normalized,
                    'calc_trace' => $trace,
                    'created_by' => (string) $actor->getKey(),
                    'updated_by' => (string) $actor->getKey(),
                ]);

                $createdByKey[$ruleKey] = $item;
            }

            // Dependency edges + checklists + reminder policies are
            // part of the same transaction: a failure anywhere rolls
            // the whole graph back (C-14).
            foreach ($createdByKey as $ruleKey => $item) {
                $definition = $definitions[$ruleKey] ?? [];

                if (isset($definition['depends_on']) && is_array($definition['depends_on'])) {
                    foreach ($definition['depends_on'] as $predecessorKey) {
                        if (! is_string($predecessorKey) || ! isset($createdByKey[$predecessorKey])) {
                            continue;
                        }
                        TaskDependency::firstOrCreate(
                            [
                                'predecessor_item_id' => (string) $createdByKey[$predecessorKey]->getKey(),
                                'successor_item_id' => (string) $item->getKey(),
                            ],
                            [
                                'org_id' => (string) $matter->org_id,
                                'matter_id' => (string) $matter->getKey(),
                            ]
                        );
                    }
                }

                if (isset($definition['checklist']) && is_array($definition['checklist'])) {
                    foreach (array_values($definition['checklist']) as $position => $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        ChecklistItem::create([
                            'item_id' => (string) $item->getKey(),
                            'label' => (string) ($entry['label'] ?? ''),
                            'required' => (bool) ($entry['required'] ?? false),
                            'done' => false,
                            'position' => $position,
                        ]);
                    }
                }

                // A reminder_policy block on the template rule becomes
                // the item's policy v1 and materializes through the
                // planner. Rules without such a block are skipped.
                $policyRules = self::reminderPolicyRules($definition);
                if ($policyRules !== null) {
                    ReminderPolicy::create([
                        'org_id' => (string) $matter->org_id,
                        'matter_id' => (string) $matter->getKey(),
                        'item_id' => (string) $item->getKey(),
                        'version' => 1,
                        'active' => true,
                        'rules' => $policyRules,
                        'created_by' => (string) $actor->getKey(),
                    ]);

                    ReminderPlanner::materialize($item);
                }
            }

            // Stamp the matter with the template it was built from
            // (009-D20; the columns are 006's template link fields).
            $matter->forceFill([
                'template_id' => $template !== null ? (string) $template->getKey() : null,
                'template_version' => (int) $version->version,
            ])->save();

            AuditLogger::log('calendar.template.instantiated', $actor, [
                'batch_id' => (string) $batch->getKey(),
                'template_version_id' => (string) $version->getKey(),
                'template_version' => (int) $version->version,
                'item_count' => count($createdByKey),
            ], $matter);

            return $batch;
        });
    }

    /**
     * A version may instantiate only when it is published and belongs
     * to the matter's org (cross-org resolves as not-found).
     *
     * @throws ValidationException
     */
    private static function assertVersionUsable(Matter $matter, DeadlineTemplateVersion $version): void
    {
        $template = $version->template;
        if ($template === null || (string) $template->org_id !== (string) $matter->org_id) {
            throw new AccessDeniedException(404);
        }

        if ($version->status !== 'published') {
            throw ValidationException::withMessages([
                'version' => "Only a published template version can be instantiated (current status: {$version->status}).",
            ]);
        }
    }

    /**
     * Run the engine, translating its input errors into 422s.
     *
     * @param  array<string, mixed>  $normalizedInputs
     * @return array{template_version: mixed, trigger_date: string,
     *               items: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private static function compute(DeadlineTemplateVersion $version, array $normalizedInputs): array
    {
        try {
            /** @var array{template_version: mixed, trigger_date: string, items: list<array<string, mixed>>} $computed */
            $computed = app(DeadlineEngine::class)->compute($version, $normalizedInputs);

            return $computed;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'inputs' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Template item definitions keyed by rule key, in the version's
     * stored content envelope shape ({items: [{key, ...}]}). Offset
     * rule lists are keyed the same way.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function itemDefinitions(DeadlineTemplateVersion $version): array
    {
        $rules = $version->rules;

        $items = null;
        if (isset($rules['items']) && is_array($rules['items'])) {
            $items = array_values($rules['items']);
        } elseif (array_is_list($rules)) {
            $items = $rules;
        }

        if ($items === null) {
            return [];
        }

        $definitions = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['key']) && is_string($item['key'])) {
                /** @var array<string, mixed> $item */
                $definitions[$item['key']] = $item;
            }
        }

        return $definitions;
    }

    /**
     * The reminder rules declared by a template item definition, or
     * null when the rule declares no reminder_policy block. Accepts
     * both fixture shapes: {reminder_policy: {rules: [...]}} and a
     * bare {reminder_policy: [...]} list.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>|null
     */
    private static function reminderPolicyRules(array $definition): ?array
    {
        if (! isset($definition['reminder_policy']) || ! is_array($definition['reminder_policy'])) {
            return null;
        }

        $policy = $definition['reminder_policy'];
        $rules = null;
        if (isset($policy['rules']) && is_array($policy['rules'])) {
            $rules = array_values($policy['rules']);
        } elseif (array_is_list($policy)) {
            $rules = $policy;
        }

        if ($rules === null || $rules === []) {
            return null;
        }

        /** @var list<array<string, mixed>> $rules */
        return $rules;
    }
}
