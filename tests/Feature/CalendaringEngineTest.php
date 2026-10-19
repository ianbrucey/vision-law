<?php

namespace Tests\Feature;

use App\Models\HolidayCalendar;
use App\Models\HolidayDate;
use App\Services\Calendaring\DeadlineEngine;
use App\Services\Calendaring\HolidayCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * C-15 (009 T-11): the deadline engine is deterministic and
 * explainable — the six hand-verified golden cases from
 * specs/009-calendaring/04-fixtures.json pass against the
 * ga-state-2026 DEMONSTRATION pack, the fixture template's chained
 * rules reproduce their hand-checked dates, replay is
 * byte-identical, and invalid rulesets are rejected by validate().
 */
class CalendaringEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('calendaring:load-holiday-packs');
    }

    /**
     * The 04-fixtures.json document, decoded once per call.
     *
     * @return array<string, mixed>
     */
    private function fixtureDocument(): array
    {
        $json = file_get_contents(base_path('specs/009-calendaring/04-fixtures.json'));
        $this->assertIsString($json);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function goldenCases(): array
    {
        foreach ($this->fixtureDocument()['fixtures'] ?? [] as $fixture) {
            if (is_array($fixture) && ($fixture['type'] ?? null) === 'golden_cases') {
                /** @var list<array<string, mixed>> $cases */
                $cases = array_values($fixture['cases'] ?? []);

                return $cases;
            }
        }

        $this->fail('golden_cases fixture not found in 04-fixtures.json');
    }

    /**
     * The published demo template's version-1 content envelope.
     *
     * @return array<string, mixed>
     */
    private function demoTemplateContent(): array
    {
        foreach ($this->fixtureDocument()['fixtures'] ?? [] as $fixture) {
            if (is_array($fixture) && ($fixture['type'] ?? null) === 'deadline_template') {
                foreach ($fixture['versions'] ?? [] as $version) {
                    if (is_array($version) && ($version['version'] ?? null) === 1 && isset($version['content']) && is_array($version['content'])) {
                        return $version['content'];
                    }
                }
            }
        }

        $this->fail('demo template v1 content not found in 04-fixtures.json');
    }

    public function test_deadline_engine_golden_cases_and_replay(): void
    {
        $engine = app(DeadlineEngine::class);

        // ── Golden cases (QA-03 set): all six hand-verified dates. ──
        $cases = $this->goldenCases();
        $this->assertCount(6, $cases);

        foreach ($cases as $case) {
            $name = (string) ($case['name'] ?? '?');
            /** @var array<string, mixed> $rule */
            $rule = is_array($case['rule'] ?? null) ? $case['rule'] : [];
            $trigger = (string) ($case['trigger'] ?? '');
            $expect = (string) ($case['expect'] ?? '');

            $this->assertSame([], $engine->validate($rule), "golden case {$name}: rule must validate");

            $trace = $engine->computeSingle($rule, $trigger, 'ga-state-2026', $name);

            $this->assertSame($expect, $trace['result_date'], "golden case {$name}");
            $this->assertSame($trigger, $trace['base_date'], "golden case {$name}: trace base date");
            $this->assertSame($name, $trace['rule_key'], "golden case {$name}: trace rule key");
            $this->assertSame('ga-state-2026', $trace['holiday_pack'], "golden case {$name}: trace pack");
            $this->assertSame('2026.1', $trace['holiday_pack_version'], "golden case {$name}: trace pack version");
            $this->assertNotSame('', (string) $trace['offset_applied'], "golden case {$name}: trace offset");
            $this->assertNotSame('', (string) $trace['explanation'], "golden case {$name}: trace explanation");
            $this->assertStringContainsString($expect, (string) $trace['explanation'], "golden case {$name}: explanation names the result");
        }

        // The holiday-sensitive cases must show the pack holiday in
        // their traces: Nov 27 2026 is a GA pack holiday.
        $byName = [];
        foreach ($cases as $case) {
            $byName[(string) $case['name']] = $case;
        }
        $businessTrace = $engine->computeSingle($byName['business-days-skip-holiday']['rule'], '2026-11-20', 'ga-state-2026', 'business-days-skip-holiday');
        $this->assertSame('2026-12-01', $businessTrace['result_date']);

        $weekendTrace = $engine->computeSingle($byName['answer-weekend-adjust']['rule'], '2026-10-30', 'ga-state-2026', 'answer-weekend-adjust');
        $this->assertSame('2026-11-30', $weekendTrace['result_date']);
        $this->assertNotEmpty($weekendTrace['adjustments'], 'weekend adjust must record an adjustment');
        $this->assertSame('weekend_shift', $weekendTrace['adjustments'][0]['type']);

        // ── Chained template rules, hand-checked: service Fri
        // 2026-10-30 → answer 2026-11-30 → disclosures 2026-12-14,
        // discovery-open 2026-12-07. ──
        $content = $this->demoTemplateContent();
        $version = ['version' => 1, 'rules' => $content];
        $inputs = ['trigger_date' => '2026-10-30', 'trigger_label' => 'Date of service of process', 'timezone' => 'America/New_York'];

        $computed = $engine->compute($version, $inputs);
        $results = [];
        foreach ($computed['items'] as $item) {
            $results[(string) $item['rule_key']] = (string) $item['result_date'];
        }

        $this->assertSame('2026-11-30', $results['answer'] ?? null, 'answer: +30 calendar days from Fri 2026-10-30, adjusted to next court day');
        $this->assertSame('2026-12-14', $results['initial-disclosures'] ?? null, 'disclosures: answer + 10 business days');
        $this->assertSame('2026-12-07', $results['discovery-open'] ?? null, 'discovery-open: answer + 7 calendar days');

        // Every chained trace carries its anchor and pack provenance.
        foreach ($computed['items'] as $item) {
            $this->assertSame(1, $item['template_version']);
            $this->assertSame('ga-state-2026', $item['holiday_pack']);
            $this->assertSame('2026.1', $item['holiday_pack_version']);
            $this->assertNotSame('', (string) $item['explanation']);
        }
        $disclosureTrace = null;
        foreach ($computed['items'] as $item) {
            if ($item['rule_key'] === 'initial-disclosures') {
                $disclosureTrace = $item;
            }
        }
        $this->assertNotNull($disclosureTrace);
        $this->assertSame('answer', $disclosureTrace['from']);
        $this->assertSame('2026-11-30', $disclosureTrace['base_date']);

        // ── Replay: same stored inputs + version ⇒ byte-identical. ──
        $replayed = $engine->compute($version, $inputs);
        $this->assertSame($computed, $replayed);
        $this->assertSame(
            json_encode($computed, JSON_THROW_ON_ERROR),
            json_encode($replayed, JSON_THROW_ON_ERROR),
            'serialized replay must be byte-identical'
        );

        // ── Invalid rulesets are rejected by validate(). ──
        $cycle = [
            ['key' => 'a', 'title' => 'A', 'kind' => 'deadline', 'from' => 'b', 'offset' => ['amount' => 1, 'unit' => 'calendar_days', 'direction' => 'after']],
            ['key' => 'b', 'title' => 'B', 'kind' => 'deadline', 'from' => 'a', 'offset' => ['amount' => 1, 'unit' => 'calendar_days', 'direction' => 'after']],
        ];
        $cycleErrors = $engine->validate($cycle);
        $this->assertNotEmpty($cycleErrors, 'a from-cycle must be rejected');
        $this->assertStringContainsStringIgnoringCase('cycle', implode(' ', $cycleErrors));

        $unknownUnit = [
            ['key' => 'a', 'title' => 'A', 'kind' => 'deadline', 'from' => 'trigger', 'offset' => ['amount' => 3, 'unit' => 'fortnights', 'direction' => 'after']],
        ];
        $unitErrors = $engine->validate($unknownUnit);
        $this->assertNotEmpty($unitErrors, 'an unknown unit must be rejected');
        $this->assertStringContainsString('fortnights', implode(' ', $unitErrors));

        $unknownOp = ['anchor' => 'service_date', 'steps' => [['op' => 'teleport_days', 'n' => 3]]];
        $this->assertNotEmpty($engine->validate($unknownOp), 'an unknown step op must be rejected');

        $unknownAnchor = ['items' => [
            ['key' => 'x', 'title' => 'X', 'rule' => ['anchor' => 'nowhere', 'steps' => [['op' => 'add_calendar_days', 'n' => 1]]]],
        ]];
        $this->assertNotEmpty($engine->validate($unknownAnchor), 'an unknown anchor must be rejected');
    }

    public function test_holiday_packs_load_idempotently_and_drive_business_day_math(): void
    {
        // setUp already loaded the packs once; a second load is a no-op.
        $calendarsAfterFirst = HolidayCalendar::count();
        $datesAfterFirst = HolidayDate::count();
        $this->assertSame(2, $calendarsAfterFirst);

        Artisan::call('calendaring:load-holiday-packs');

        $this->assertSame($calendarsAfterFirst, HolidayCalendar::count());
        $this->assertSame($datesAfterFirst, HolidayDate::count());

        $ga = HolidayCalendar::query()->where('key', 'ga-state-2026')->firstOrFail();
        $this->assertTrue((bool) $ga->demonstration);
        $this->assertSame('2026.1', $ga->version);

        $service = app(HolidayCalendarService::class);

        // Thanksgiving Thu 2026-11-26 AND Fri 2026-11-27 are GA holidays.
        $this->assertTrue($service->isHoliday('ga-state-2026', '2026-11-26'));
        $this->assertTrue($service->isHoliday('ga-state-2026', '2026-11-27'));
        $this->assertFalse($service->isHoliday('us-federal-2026', '2026-11-27'));

        $this->assertFalse($service->isBusinessDay('2026-11-29', 'ga-state-2026'), 'Sunday is not a business day');
        $this->assertTrue($service->isBusinessDay('2026-11-30', 'ga-state-2026'));
        $this->assertFalse($service->isBusinessDay('2026-11-27', 'ga-state-2026'), 'pack holiday is not a business day');
        $this->assertTrue($service->isBusinessDay('2026-11-27', null), 'without a pack, Fri Nov 27 is a business day');

        // Fri Nov 20 + 5 business days over the GA pack = Tue Dec 1
        // (the golden business-days-skip-holiday case, via the service).
        $this->assertSame('2026-12-01', $service->addBusinessDays('2026-11-20', 5, 'ga-state-2026'));
        $this->assertSame('2026-11-20', $service->addBusinessDays('2026-11-20', 0, 'ga-state-2026'));

        // Adjust-forward from Sunday Nov 29 lands on Monday Nov 30.
        $this->assertSame('2026-11-30', $service->nextBusinessDay('2026-11-29', 'ga-state-2026'));
        $this->assertSame('2026-11-30', $service->nextBusinessDay('2026-11-30', 'ga-state-2026'));
        // Adjust-forward from Thanksgiving skips both pack holidays
        // and the weekend: Thu Nov 26 → Mon Nov 30.
        $this->assertSame('2026-11-30', $service->nextBusinessDay('2026-11-26', 'ga-state-2026'));
    }

    public function test_engine_offset_rules_match_the_step_rules(): void
    {
        $engine = app(DeadlineEngine::class);

        // The same chain expressed in the offset representation must
        // produce the same hand-checked dates as the step rules.
        $rules = [
            ['key' => 'answer', 'title' => 'Answer due', 'kind' => 'deadline', 'from' => 'trigger',
                'offset' => ['amount' => 30, 'unit' => 'calendar_days', 'direction' => 'after'],
                'adjust' => ['weekend' => 'next_business_day', 'holiday_pack' => 'ga-state-2026']],
            ['key' => 'initial-disclosures', 'title' => 'Initial disclosures due', 'kind' => 'deadline', 'from' => 'answer',
                'offset' => ['amount' => 10, 'unit' => 'business_days', 'direction' => 'after'],
                'adjust' => ['weekend' => 'next_business_day', 'holiday_pack' => 'ga-state-2026']],
            ['key' => 'discovery-open', 'title' => 'Open discovery plan', 'kind' => 'task', 'from' => 'answer',
                'offset' => ['amount' => 7, 'unit' => 'calendar_days', 'direction' => 'after'],
                'adjust' => ['weekend' => 'none', 'holiday_pack' => null]],
        ];

        $this->assertSame([], $engine->validate($rules));

        $computed = $engine->compute(['version' => 7, 'rules' => $rules], ['trigger_date' => '2026-10-30']);
        $results = [];
        foreach ($computed['items'] as $item) {
            $results[(string) $item['rule_key']] = (string) $item['result_date'];
            $this->assertSame(7, $item['template_version']);
        }

        $this->assertSame('2026-11-30', $results['answer'] ?? null);
        $this->assertSame('2026-12-14', $results['initial-disclosures'] ?? null);
        $this->assertSame('2026-12-07', $results['discovery-open'] ?? null);

        // court_days count against the rule's holiday pack:
        // Wed Nov 25 + 3 court days = Wed Dec 2 (golden case 3).
        $courtTrace = $engine->computeSingle(
            ['offset' => ['amount' => 3, 'unit' => 'court_days', 'direction' => 'after'], 'adjust' => ['weekend' => 'none', 'holiday_pack' => 'ga-state-2026']],
            '2026-11-25',
            'ga-state-2026',
            'court-days-skip-holiday'
        );
        $this->assertSame('2026-12-02', $courtTrace['result_date']);
    }
}
