<?php

namespace App\Services\Calendaring;

use App\Models\DeadlineTemplateVersion;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Deterministic deadline engine (009-D19, T-11).
 *
 * PURE: no now(), no DB writes, no randomness. Holiday lookups go
 * through HolidayCalendarService over the loaded versioned packs.
 * The same version + inputs always produce a byte-identical output
 * array (fixed key order, declaration-ordered topological evaluation).
 *
 * Two rule representations are supported — they are the same DSL in
 * two envelopes:
 *
 *  A) Offset rules (catalog shape):
 *     {key, title, kind: deadline|event|task,
 *      from: "trigger"|<rule key>,
 *      offset: {amount: int, unit: calendar_days|business_days|court_days,
 *               direction: after|before},
 *      adjust: {weekend: next_business_day|none, holiday_pack: <key>|null},
 *      at_time: ?string, criticality: ?string}
 *
 *  B) Step rules (009-D19 / fixture template content shape):
 *     content = {holiday_pack: <key>, triggers: [{key, ...}],
 *                items: [{key, title, type|kind, classification,
 *                         rule: {anchor: <trigger key>|<item key>,
 *                                steps: [{op, n} | {op: adjust, mode} |
 *                                        {op: set_cutoff, time}],
 *                                cutoff: ?string},
 *                         depends_on: [<item key>]}]}
 *     A single golden-case rule {anchor, steps} is shape B with one
 *     implicit item (see computeSingle()).
 *
 * Allowlisted step ops: add_calendar_days, add_business_days,
 * add_court_days, adjust, set_cutoff. court_days are business days
 * counted against the rule's holiday pack. The trigger day is day 0
 * and is never counted.
 */
class DeadlineEngine
{
    /** @var list<string> */
    public const ALLOWED_UNITS = ['calendar_days', 'business_days', 'court_days'];

    /** @var list<string> */
    public const ALLOWED_OPS = ['add_calendar_days', 'add_business_days', 'add_court_days', 'adjust', 'set_cutoff'];

    /** @var list<string> */
    public const ALLOWED_ADJUST_MODES = ['next_business_day', 'prev_business_day', 'next_court_day', 'none'];

    /** @var list<string> */
    public const ALLOWED_KINDS = ['deadline', 'event', 'task'];

    /** @var list<string> */
    public const ALLOWED_DIRECTIONS = ['after', 'before'];

    /** @var list<string> */
    public const ALLOWED_WEEKEND_ADJUST = ['next_business_day', 'none'];

    public function __construct(private readonly HolidayCalendarService $holidays) {}

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * Validate a ruleset in either representation. Returns a list of
     * human-readable errors; an empty list means valid. Save/publish
     * paths surface these as 422s (TemplateService).
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $rules
     * @return list<string>
     */
    public function validate(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        // Single step rule (golden-case shape): {anchor, steps}.
        if (isset($rules['steps']) && is_array($rules['steps'])) {
            return $this->validateSteps($rules['steps'], 'rule');
        }

        // Content envelope (shape B): {items: [...]}.
        if (isset($rules['items'])) {
            return $this->validateContent($rules);
        }

        if (array_is_list($rules)) {
            $first = $rules[0] ?? null;

            if (is_array($first) && (isset($first['rule']) || isset($first['steps']))) {
                return $this->validateContent(['items' => $rules]);
            }

            /** @var list<array<string, mixed>> $rules */
            return $this->validateOffsetRules($rules);
        }

        // Single offset rule (assoc array with a key).
        if (isset($rules['key'])) {
            /** @var array<string, mixed> $rules */
            return $this->validateOffsetRules([$rules]);
        }

        return ['Ruleset shape is not recognized.'];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<string>
     */
    private function validateOffsetRules(array $rules): array
    {
        $errors = [];
        $keys = [];

        foreach ($rules as $rule) {
            $key = isset($rule['key']) && is_string($rule['key']) ? $rule['key'] : '';

            if ($key === '') {
                $errors[] = 'Every rule requires a non-empty string key.';

                continue;
            }

            if (in_array($key, $keys, true)) {
                $errors[] = "Duplicate rule key '{$key}'.";
            }
            $keys[] = $key;
        }

        foreach ($rules as $rule) {
            $key = isset($rule['key']) && is_string($rule['key']) ? $rule['key'] : '?';

            if (isset($rule['kind']) && (! is_string($rule['kind']) || ! in_array($rule['kind'], self::ALLOWED_KINDS, true))) {
                $errors[] = "Rule '{$key}' uses unknown kind '".(is_scalar($rule['kind']) ? (string) $rule['kind'] : '?')."'.";
            }

            if (isset($rule['from']) && is_string($rule['from']) && $rule['from'] !== 'trigger' && ! in_array($rule['from'], $keys, true)) {
                $errors[] = "Rule '{$key}' references unknown 'from' value '{$rule['from']}'.";
            }

            if (isset($rule['offset'])) {
                if (! is_array($rule['offset'])) {
                    $errors[] = "Rule '{$key}' offset must be an object.";
                } else {
                    $offset = $rule['offset'];
                    if (isset($offset['unit']) && (! is_string($offset['unit']) || ! in_array($offset['unit'], self::ALLOWED_UNITS, true))) {
                        $errors[] = "Rule '{$key}' uses unknown unit '".(is_scalar($offset['unit']) ? (string) $offset['unit'] : '?')."'.";
                    }
                    if (isset($offset['direction']) && (! is_string($offset['direction']) || ! in_array($offset['direction'], self::ALLOWED_DIRECTIONS, true))) {
                        $errors[] = "Rule '{$key}' uses unknown direction '".(is_scalar($offset['direction']) ? (string) $offset['direction'] : '?')."'.";
                    }
                    if (isset($offset['amount']) && ! is_int($offset['amount'])) {
                        $errors[] = "Rule '{$key}' offset amount must be an integer.";
                    }
                }
            }

            if (isset($rule['adjust']) && is_array($rule['adjust'])) {
                $weekend = $rule['adjust']['weekend'] ?? null;
                if ($weekend !== null && (! is_string($weekend) || ! in_array($weekend, self::ALLOWED_WEEKEND_ADJUST, true))) {
                    $errors[] = "Rule '{$key}' uses unknown weekend adjustment '".(is_scalar($weekend) ? (string) $weekend : '?')."'.";
                }
            }
        }

        // Cycle detection over the from-graph.
        $graph = [];
        foreach ($rules as $rule) {
            if (isset($rule['key']) && is_string($rule['key'])) {
                $from = isset($rule['from']) && is_string($rule['from']) ? $rule['from'] : 'trigger';
                $graph[$rule['key']] = $from === 'trigger' ? [] : [$from];
            }
        }
        foreach ($this->cycleErrors($graph) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * @param  array<int|string, mixed>  $content
     * @return list<string>
     */
    private function validateContent(array $content): array
    {
        $errors = [];

        $triggerKeys = ['trigger'];
        if (isset($content['triggers']) && is_array($content['triggers'])) {
            foreach ($content['triggers'] as $trigger) {
                if (is_array($trigger) && isset($trigger['key']) && is_string($trigger['key'])) {
                    $triggerKeys[] = $trigger['key'];
                }
            }
        }

        $items = isset($content['items']) && is_array($content['items']) ? array_values($content['items']) : [];
        $itemKeys = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $errors[] = 'Every template item must be an object.';

                continue;
            }
            $key = isset($item['key']) && is_string($item['key']) ? $item['key'] : '';
            if ($key === '') {
                $errors[] = 'Every template item requires a non-empty string key.';

                continue;
            }
            if (in_array($key, $itemKeys, true)) {
                $errors[] = "Duplicate item key '{$key}'.";
            }
            $itemKeys[] = $key;
        }

        $knownAnchors = array_merge($triggerKeys, $itemKeys);
        $graph = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['key']) || ! is_string($item['key'])) {
                continue;
            }
            $key = $item['key'];
            $edges = [];

            $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : null;
            if ($rule !== null) {
                $anchor = isset($rule['anchor']) && is_string($rule['anchor']) ? $rule['anchor'] : 'trigger';
                if (! in_array($anchor, $knownAnchors, true)) {
                    $errors[] = "Item '{$key}' references unknown anchor '{$anchor}'.";
                } elseif ($anchor !== 'trigger' && ! in_array($anchor, $triggerKeys, true)) {
                    $edges[] = $anchor;
                }

                if (isset($rule['steps'])) {
                    if (! is_array($rule['steps'])) {
                        $errors[] = "Item '{$key}' steps must be a list.";
                    } else {
                        foreach ($this->validateSteps($rule['steps'], $key) as $error) {
                            $errors[] = $error;
                        }
                    }
                }
            }

            if (isset($item['depends_on']) && is_array($item['depends_on'])) {
                foreach ($item['depends_on'] as $dependency) {
                    if (! is_string($dependency) || ! in_array($dependency, $itemKeys, true)) {
                        $errors[] = "Item '{$key}' depends on unknown item '".(is_scalar($dependency) ? (string) $dependency : '?')."'.";
                    } elseif ($dependency !== $key) {
                        $edges[] = $dependency;
                    } elseif ($dependency === $key) {
                        $errors[] = "Item '{$key}' depends on itself (cycle).";
                    }
                }
            }

            $graph[$key] = $edges;
        }

        foreach ($this->cycleErrors($graph) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return list<string>
     */
    private function validateSteps(array $steps, string $context): array
    {
        $errors = [];

        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['op']) || ! is_string($step['op'])) {
                $errors[] = "Rule '{$context}' has a step without an op.";

                continue;
            }

            $op = $step['op'];
            if (! in_array($op, self::ALLOWED_OPS, true)) {
                $errors[] = "Rule '{$context}' uses unknown op '{$op}'.";

                continue;
            }

            if (in_array($op, ['add_calendar_days', 'add_business_days', 'add_court_days'], true) && ! isset($step['n'])) {
                // The catalog envelope spells the amount "amount"; the
                // step envelope spells it "n". Exactly one is required.
                if (! isset($step['amount'])) {
                    $errors[] = "Rule '{$context}' step '{$op}' requires an amount (n).";
                }
            }

            if ($op === 'adjust') {
                $mode = isset($step['mode']) && is_string($step['mode']) ? $step['mode'] : '';
                if (! in_array($mode, self::ALLOWED_ADJUST_MODES, true)) {
                    $errors[] = "Rule '{$context}' uses unknown adjust mode '{$mode}'.";
                }
            }
        }

        return $errors;
    }

    /**
     * DFS cycle detection. Returns one error per cycle found.
     *
     * @param  array<string, list<string>>  $graph  node => prerequisite nodes
     * @return list<string>
     */
    private function cycleErrors(array $graph): array
    {
        $errors = [];
        $state = []; // 0 = unvisited, 1 = in stack, 2 = done

        $visit = function (string $node, array $stack) use (&$visit, &$state, &$errors, $graph): void {
            $state[$node] = 1;
            $stack[] = $node;

            foreach ($graph[$node] ?? [] as $next) {
                if (! array_key_exists($next, $graph)) {
                    continue;
                }
                $nextState = $state[$next] ?? 0;
                if ($nextState === 1) {
                    $errors[] = "Rule graph contains a cycle involving '{$next}' (path: ".implode(' -> ', [...$stack, $next]).').';
                } elseif ($nextState === 0) {
                    $visit($next, $stack);
                }
            }

            $state[$node] = 2;
        };

        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node, []);
            }
        }

        return $errors;
    }

    // ----------------------------------------------------------------
    // Computation
    // ----------------------------------------------------------------

    /**
     * Compute every rule in a template version from stored inputs.
     *
     * @param  DeadlineTemplateVersion|array<string, mixed>  $version
     * @param  array<string, mixed>  $inputs  {trigger_date: Y-m-d,
     *                                        trigger_label?: string,
     *                                        trigger_key?: string,
     *                                        timezone?: string,
     *                                        triggers?: array<string, string>,
     *                                        holiday_pack?: string}
     * @return array{template_version: mixed, trigger_date: string,
     *               items: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when inputs are missing or the
     *                                  ruleset is invalid
     */
    public function compute(DeadlineTemplateVersion|array $version, array $inputs): array
    {
        [$versionLabel, $payload] = $this->resolveVersion($version);

        $errors = $this->validate($payload);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid ruleset: '.implode(' ', $errors));
        }

        if (! isset($inputs['trigger_date']) || ! is_string($inputs['trigger_date'])) {
            throw new InvalidArgumentException('compute() requires inputs.trigger_date (Y-m-d).');
        }
        $triggerDate = Carbon::createFromFormat('Y-m-d', $inputs['trigger_date']);
        if ($triggerDate === null) {
            throw new InvalidArgumentException('inputs.trigger_date must be a Y-m-d date.');
        }
        $triggerDate = $triggerDate->toDateString();

        $triggerKey = isset($inputs['trigger_key']) && is_string($inputs['trigger_key']) && $inputs['trigger_key'] !== ''
            ? $inputs['trigger_key']
            : 'service_date';

        /** @var array<string, string> $triggerDates */
        $triggerDates = ['trigger' => $triggerDate, $triggerKey => $triggerDate];
        if (isset($inputs['triggers']) && is_array($inputs['triggers'])) {
            foreach ($inputs['triggers'] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $triggerDates[$key] = Carbon::parse($value)->toDateString();
                }
            }
        }

        $defaultPack = null;
        if (isset($payload['holiday_pack']) && is_string($payload['holiday_pack'])) {
            $defaultPack = $payload['holiday_pack'];
        }
        if (isset($inputs['holiday_pack']) && is_string($inputs['holiday_pack'])) {
            $defaultPack = $inputs['holiday_pack'];
        }

        $nodes = $this->normalizeNodes($payload, $defaultPack);
        $ordered = $this->topologicalOrder($nodes);

        /** @var array<string, string> $results */
        $results = [];
        $items = [];

        foreach ($ordered as $node) {
            $anchor = $node['from'];
            $baseDate = $triggerDates[$anchor] ?? $results[$anchor] ?? null;
            if ($baseDate === null) {
                throw new InvalidArgumentException("Rule '{$node['key']}' anchor '{$anchor}' has no date.");
            }

            $trace = $this->evaluateNode($node, $baseDate, $versionLabel);
            $results[$node['key']] = $trace['result_date'];
            $items[] = $trace;
        }

        return [
            'template_version' => $versionLabel,
            'trigger_date' => $triggerDate,
            'items' => $items,
        ];
    }

    /**
     * Compute a single rule (either representation) from one trigger
     * date. Used by the golden-case suite: the rule is a fixture
     * {anchor, steps} object or an offset rule {offset, adjust}.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed> the trace for the single rule
     */
    public function computeSingle(array $rule, string $triggerDate, ?string $holidayPack = null, string $ruleKey = 'rule', mixed $versionLabel = 1): array
    {
        $baseDate = Carbon::parse($triggerDate)->toDateString();

        if (isset($rule['steps']) && is_array($rule['steps'])) {
            $node = [
                'key' => $ruleKey,
                'title' => $ruleKey,
                'kind' => 'deadline',
                'from' => isset($rule['anchor']) && is_string($rule['anchor']) ? $rule['anchor'] : 'trigger',
                'depends_on' => [],
                'mode' => 'steps',
                'steps' => array_values($rule['steps']),
                'offset' => null,
                'adjust' => null,
                'cutoff' => isset($rule['cutoff']) && is_string($rule['cutoff']) ? $rule['cutoff'] : null,
                'holiday_pack' => $holidayPack,
                'criticality' => null,
                'at_time' => null,
            ];
        } else {
            $node = [
                'key' => $ruleKey,
                'title' => isset($rule['title']) && is_string($rule['title']) ? $rule['title'] : $ruleKey,
                'kind' => isset($rule['kind']) && is_string($rule['kind']) ? $rule['kind'] : 'deadline',
                'from' => isset($rule['from']) && is_string($rule['from']) ? $rule['from'] : 'trigger',
                'depends_on' => [],
                'mode' => 'offset',
                'steps' => [],
                'offset' => isset($rule['offset']) && is_array($rule['offset']) ? $rule['offset'] : null,
                'adjust' => isset($rule['adjust']) && is_array($rule['adjust']) ? $rule['adjust'] : null,
                'cutoff' => null,
                'holiday_pack' => $holidayPack,
                'criticality' => isset($rule['criticality']) && is_string($rule['criticality']) ? $rule['criticality'] : null,
                'at_time' => isset($rule['at_time']) && is_string($rule['at_time']) ? $rule['at_time'] : null,
            ];
        }

        return $this->evaluateNode($node, $baseDate, $versionLabel);
    }

    /**
     * @param  DeadlineTemplateVersion|array<string, mixed>  $version
     * @return array{0: mixed, 1: array<string, mixed>|list<array<string, mixed>>}
     */
    private function resolveVersion(DeadlineTemplateVersion|array $version): array
    {
        if ($version instanceof DeadlineTemplateVersion) {
            return [$version->version, $version->rules];
        }

        if (isset($version['rules']) && is_array($version['rules'])) {
            return [$version['version'] ?? 1, $version['rules']];
        }

        if (isset($version['content']) && is_array($version['content'])) {
            return [$version['version'] ?? 1, $version['content']];
        }

        // The array IS the ruleset / content envelope.
        return [$version['version'] ?? 1, $version];
    }

    /**
     * Normalize either representation into evaluation nodes, in
     * declaration order.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(array $payload, ?string $defaultPack): array
    {
        $nodes = [];

        // Shape B: content envelope or bare items list.
        $items = null;
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = array_values($payload['items']);
        } elseif (array_is_list($payload) && isset($payload[0]) && is_array($payload[0]) && isset($payload[0]['rule'])) {
            $items = $payload;
        }

        if ($items !== null) {
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['key']) || ! is_string($item['key'])) {
                    continue;
                }
                $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : [];
                $dependsOn = [];
                if (isset($item['depends_on']) && is_array($item['depends_on'])) {
                    foreach ($item['depends_on'] as $dependency) {
                        if (is_string($dependency)) {
                            $dependsOn[] = $dependency;
                        }
                    }
                }
                $nodes[] = [
                    'key' => $item['key'],
                    'title' => isset($item['title']) && is_string($item['title']) ? $item['title'] : $item['key'],
                    'kind' => isset($item['type']) && is_string($item['type']) ? $item['type'] : (isset($item['kind']) && is_string($item['kind']) ? $item['kind'] : 'deadline'),
                    'from' => isset($rule['anchor']) && is_string($rule['anchor']) ? $rule['anchor'] : 'trigger',
                    'depends_on' => $dependsOn,
                    'mode' => 'steps',
                    'steps' => isset($rule['steps']) && is_array($rule['steps']) ? array_values($rule['steps']) : [],
                    'offset' => null,
                    'adjust' => null,
                    'cutoff' => isset($rule['cutoff']) && is_string($rule['cutoff']) ? $rule['cutoff'] : null,
                    'holiday_pack' => isset($rule['holiday_pack']) && is_string($rule['holiday_pack']) ? $rule['holiday_pack'] : $defaultPack,
                    'criticality' => isset($item['classification']) && is_string($item['classification']) ? $item['classification'] : null,
                    'at_time' => null,
                ];
            }

            return $nodes;
        }

        // Shape A: offset rule list (or a single rule).
        $rules = array_is_list($payload) ? $payload : [$payload];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! isset($rule['key']) || ! is_string($rule['key'])) {
                continue;
            }
            $adjust = isset($rule['adjust']) && is_array($rule['adjust']) ? $rule['adjust'] : null;
            $pack = $defaultPack;
            if ($adjust !== null && isset($adjust['holiday_pack']) && is_string($adjust['holiday_pack'])) {
                $pack = $adjust['holiday_pack'];
            }
            $nodes[] = [
                'key' => $rule['key'],
                'title' => isset($rule['title']) && is_string($rule['title']) ? $rule['title'] : $rule['key'],
                'kind' => isset($rule['kind']) && is_string($rule['kind']) ? $rule['kind'] : 'deadline',
                'from' => isset($rule['from']) && is_string($rule['from']) ? $rule['from'] : 'trigger',
                'depends_on' => [],
                'mode' => 'offset',
                'steps' => [],
                'offset' => isset($rule['offset']) && is_array($rule['offset']) ? $rule['offset'] : null,
                'adjust' => $adjust,
                'cutoff' => null,
                'holiday_pack' => $pack,
                'criticality' => isset($rule['criticality']) && is_string($rule['criticality']) ? $rule['criticality'] : null,
                'at_time' => isset($rule['at_time']) && is_string($rule['at_time']) ? $rule['at_time'] : null,
            ];
        }

        return $nodes;
    }

    /**
     * Kahn topological sort with declaration-order tie-breaking, so
     * the evaluation order — and therefore the output — is stable.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function topologicalOrder(array $nodes): array
    {
        $byKey = [];
        foreach ($nodes as $node) {
            $byKey[(string) $node['key']] = $node;
        }

        $ordered = [];
        $emitted = [];
        $remaining = $nodes;

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $index => $node) {
                $prerequisites = [];
                $from = (string) $node['from'];
                if (isset($byKey[$from])) {
                    $prerequisites[] = $from;
                }
                foreach ((array) $node['depends_on'] as $dependency) {
                    if (is_string($dependency) && isset($byKey[$dependency])) {
                        $prerequisites[] = $dependency;
                    }
                }

                $ready = true;
                foreach ($prerequisites as $prerequisite) {
                    if (! isset($emitted[$prerequisite])) {
                        $ready = false;
                        break;
                    }
                }

                if ($ready) {
                    $ordered[] = $node;
                    $emitted[(string) $node['key']] = true;
                    unset($remaining[$index]);
                    $progress = true;
                }
            }

            if (! $progress) {
                throw new InvalidArgumentException('Rule graph contains a cycle; evaluation aborted.');
            }
        }

        return $ordered;
    }

    /**
     * Evaluate one node from its base date and build its trace.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function evaluateNode(array $node, string $baseDate, mixed $versionLabel): array
    {
        $pack = isset($node['holiday_pack']) && is_string($node['holiday_pack']) ? $node['holiday_pack'] : null;
        $current = $baseDate;
        $stepTraces = [];
        $adjustments = [];
        $offsetParts = [];
        $cutoff = isset($node['cutoff']) && is_string($node['cutoff']) ? $node['cutoff'] : null;

        if ($node['mode'] === 'steps') {
            /** @var list<array<string, mixed>> $steps */
            $steps = is_array($node['steps']) ? $node['steps'] : [];
            foreach ($steps as $step) {
                $op = isset($step['op']) && is_string($step['op']) ? $step['op'] : '';

                if (in_array($op, ['add_calendar_days', 'add_business_days', 'add_court_days'], true)) {
                    $amount = isset($step['n']) && is_int($step['n']) ? $step['n'] : (isset($step['amount']) && is_int($step['amount']) ? $step['amount'] : 0);
                    $fromDate = $current;
                    $current = $this->applyAdd($current, $op, $amount, $pack);
                    $stepTraces[] = ['op' => $op, 'amount' => $amount, 'from_date' => $fromDate, 'to_date' => $current];
                    $offsetParts[] = "{$op}({$amount})";
                } elseif ($op === 'adjust') {
                    $mode = isset($step['mode']) && is_string($step['mode']) ? $step['mode'] : 'none';
                    $fromDate = $current;
                    [$current, $stepAdjustments] = $this->applyAdjust($current, $mode, $pack);
                    foreach ($stepAdjustments as $adjustment) {
                        $adjustments[] = $adjustment;
                    }
                    $stepTraces[] = ['op' => 'adjust', 'mode' => $mode, 'from_date' => $fromDate, 'to_date' => $current];
                    $offsetParts[] = "adjust({$mode})";
                } elseif ($op === 'set_cutoff') {
                    $cutoff = isset($step['time']) && is_string($step['time']) ? $step['time'] : $cutoff;
                    $stepTraces[] = ['op' => 'set_cutoff', 'time' => $cutoff, 'from_date' => $current, 'to_date' => $current];
                    $offsetParts[] = "set_cutoff({$cutoff})";
                }
            }
        } else {
            $offset = isset($node['offset']) && is_array($node['offset']) ? $node['offset'] : null;
            if ($offset !== null) {
                $amount = isset($offset['amount']) && is_int($offset['amount']) ? $offset['amount'] : 0;
                $unit = isset($offset['unit']) && is_string($offset['unit']) ? $offset['unit'] : 'calendar_days';
                $direction = isset($offset['direction']) && is_string($offset['direction']) ? $offset['direction'] : 'after';
                $signed = $direction === 'before' ? -$amount : $amount;
                $op = match ($unit) {
                    'business_days' => 'add_business_days',
                    'court_days' => 'add_court_days',
                    default => 'add_calendar_days',
                };
                $fromDate = $current;
                $current = $this->applyAdd($current, $op, $signed, $pack);
                $stepTraces[] = ['op' => $op, 'amount' => $signed, 'from_date' => $fromDate, 'to_date' => $current];
                $offsetParts[] = ($signed >= 0 ? '+' : '').$signed.' '.$unit;
            }

            $adjust = isset($node['adjust']) && is_array($node['adjust']) ? $node['adjust'] : null;
            $weekend = $adjust !== null && isset($adjust['weekend']) && is_string($adjust['weekend']) ? $adjust['weekend'] : 'none';
            if ($weekend === 'next_business_day') {
                $fromDate = $current;
                [$current, $stepAdjustments] = $this->applyAdjust($current, 'next_business_day', $pack);
                foreach ($stepAdjustments as $adjustment) {
                    $adjustments[] = $adjustment;
                }
                $stepTraces[] = ['op' => 'adjust', 'mode' => 'next_business_day', 'from_date' => $fromDate, 'to_date' => $current];
                $offsetParts[] = 'adjust(next_business_day)';
            }

            if (isset($node['at_time']) && is_string($node['at_time'])) {
                $cutoff = $node['at_time'];
            }
        }

        $offsetApplied = implode('; ', $offsetParts);
        $explanation = $this->explainNode($node, $baseDate, $offsetApplied, $adjustments, $current, $pack);

        return [
            'rule_key' => (string) $node['key'],
            'title' => (string) $node['title'],
            'kind' => (string) $node['kind'],
            'template_version' => $versionLabel,
            'from' => (string) $node['from'],
            'base_date' => $baseDate,
            'offset_applied' => $offsetApplied,
            'steps' => $stepTraces,
            'adjustments' => $adjustments,
            'holiday_pack' => $pack,
            'holiday_pack_version' => $pack !== null ? $this->holidays->packVersion($pack) : null,
            'cutoff' => $cutoff,
            'result_date' => $current,
            'explanation' => $explanation,
        ];
    }

    private function applyAdd(string $date, string $op, int $amount, ?string $pack): string
    {
        return match ($op) {
            'add_business_days', 'add_court_days' => $this->holidays->addBusinessDays($date, $amount, $pack),
            default => Carbon::parse($date)->addDays($amount)->toDateString(),
        };
    }

    /**
     * Apply a weekend/holiday adjustment, returning the new date and
     * the adjustment records for the trace.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function applyAdjust(string $date, string $mode, ?string $pack): array
    {
        if ($mode === 'none') {
            return [$date, []];
        }

        $forward = $mode !== 'prev_business_day';
        $day = Carbon::parse($date);
        $crossedWeekend = false;
        $crossedHolidays = [];

        while (! $this->holidays->isBusinessDay($day->toDateString(), $pack)) {
            if ($day->isWeekend()) {
                $crossedWeekend = true;
            }
            if ($pack !== null) {
                $name = $this->holidays->holidayName($pack, $day->toDateString());
                if ($name !== null) {
                    $crossedHolidays[] = ['date' => $day->toDateString(), 'name' => $name];
                }
            }
            $day = $forward ? $day->copy()->addDay() : $day->copy()->subDay();
        }

        $result = $day->toDateString();
        if ($result === $date) {
            return [$date, []];
        }

        $adjustments = [];
        if ($crossedWeekend) {
            $adjustments[] = ['type' => 'weekend_shift', 'from' => $date, 'to' => $result];
        }
        if ($crossedHolidays !== []) {
            $adjustments[] = [
                'type' => 'holiday_shift',
                'pack' => $pack,
                'pack_version' => $pack !== null ? $this->holidays->packVersion($pack) : null,
                'dates' => $crossedHolidays,
                'from' => $date,
                'to' => $result,
            ];
        }
        if ($adjustments === []) {
            $adjustments[] = ['type' => 'business_day_shift', 'from' => $date, 'to' => $result];
        }

        return [$result, $adjustments];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $adjustments
     */
    private function explainNode(array $node, string $baseDate, string $offsetApplied, array $adjustments, string $resultDate, ?string $pack): string
    {
        $title = (string) $node['title'];
        $from = (string) $node['from'];
        $sentence = "{$title}: {$offsetApplied} from {$from} ({$baseDate}) = {$resultDate}.";

        if ($adjustments !== []) {
            $kinds = [];
            foreach ($adjustments as $adjustment) {
                $kinds[] = str_replace('_', ' ', (string) ($adjustment['type'] ?? 'adjustment'));
            }
            $packNote = $pack !== null ? " (pack {$pack} v".($this->holidays->packVersion($pack) ?? 'unloaded').')' : '';
            $sentence .= ' Adjusted for '.implode(' and ', array_unique($kinds)).$packNote.'.';
        }

        return $sentence;
    }
}
