<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\InstantiationBatch;
use App\Models\ReminderPolicy;
use App\Models\TaskDependency;
use App\Services\Calendaring\InstantiationService;
use App\Services\Calendaring\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-14 (009 T-12): template instantiation — a published template
 * version is previewed (persisting nothing), then confirmed in one
 * transaction with an idempotency key; generated items carry the
 * golden dates for the fixture trigger plus full provenance (rule
 * key, template version, calculation trace); a duplicate confirm
 * creates nothing new; a draft version cannot instantiate; a forced
 * mid-creation failure rolls the whole graph back.
 */
class CalendaringInstantiationTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
        Artisan::call('calendaring:load-holiday-packs');
    }

    private function publishedVersion(): DeadlineTemplateVersion
    {
        $template = $this->loader->deadlineTemplate('tpl-demo-litigation');

        return DeadlineTemplateVersion::query()
            ->where('template_id', (string) $template->getKey())
            ->where('version', 1)
            ->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function inputs(): array
    {
        return [
            'trigger_date' => '2026-10-30',
            'service_date' => '2026-10-30',
            'timezone' => 'America/New_York',
        ];
    }

    public function test_template_instantiation_is_previewed_transactional_and_provenanced(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $viewer = $this->loader->user('user-viewer');
        $matter = $this->loader->matter('matter-1');
        $version = $this->publishedVersion();
        $inputs = $this->inputs();

        $itemsBefore = CalendarItem::query()->where('matter_id', (string) $matter->getKey())->count();

        // ── Preview (service): golden dates, persists NOTHING. ──
        $preview = InstantiationService::preview($matter, $version, $inputs);

        $byKey = [];
        foreach ($preview['items'] as $item) {
            $byKey[(string) $item['rule_key']] = $item;
        }
        $this->assertSame('2026-11-30', $byKey['answer']['result_date'] ?? null, 'answer: service 2026-10-30 + 30 calendar days, next court day');
        $this->assertSame('2026-12-14', $byKey['initial-disclosures']['result_date'] ?? null, 'disclosures: answer + 10 business days');
        $this->assertSame('2026-12-07', $byKey['discovery-open']['result_date'] ?? null, 'discovery-open: answer + 7 calendar days');
        foreach ($preview['items'] as $item) {
            $this->assertSame(1, $item['template_version'], 'every preview trace names the template version');
            $this->assertNotSame('', (string) $item['explanation']);
        }

        $this->assertSame(0, InstantiationBatch::count(), 'preview creates no batch');
        $this->assertSame($itemsBefore, CalendarItem::query()->where('matter_id', (string) $matter->getKey())->count(), 'preview creates no items');

        // ── Preview (HTTP): same computation, still nothing stored,
        // and a user without matter edit access gets no signal. ──
        $httpPreview = $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.preview', $matter),
            ['template_version_id' => (string) $version->getKey(), 'inputs' => $inputs]
        );
        $httpPreview->assertOk();
        $httpPreview->assertJsonPath('items.0.result_date', '2026-11-30');
        $this->assertSame(0, InstantiationBatch::count());

        $this->actingAs($viewer)->postJson(
            route('calendar.instantiations.preview', $matter),
            ['template_version_id' => (string) $version->getKey(), 'inputs' => $inputs]
        )->assertNotFound();

        // ── Confirm (HTTP): the chain is created with provenance. ──
        $confirm = $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.store', $matter),
            ['template_version_id' => (string) $version->getKey(), 'inputs' => $inputs, 'idempotency_key' => 'c14-confirm-1']
        );
        $confirm->assertCreated();
        $batchId = $confirm->json('id');
        $this->assertIsString($batchId);
        $confirm->assertJsonPath('item_count', 3);

        $batch = InstantiationBatch::findOrFail($batchId);
        $this->assertSame((string) $version->getKey(), (string) $batch->template_version_id);
        $this->assertSame('2026-10-30', $batch->inputs['trigger_date'] ?? null, 'batch stores the inputs it was computed from');
        $this->assertSame((string) $attorney->getKey(), (string) $batch->created_by);

        $items = CalendarItem::query()
            ->where('instantiation_batch_id', $batchId)
            ->get()
            ->keyBy(fn (CalendarItem $item) => (string) $item->rule_key);

        $this->assertCount(3, $items);
        $this->assertSame('2026-11-30', $items['answer']->due_date?->toDateString());
        $this->assertSame('2026-12-14', $items['initial-disclosures']->due_date?->toDateString());
        $this->assertSame('2026-12-07', $items['discovery-open']->due_date?->toDateString());

        foreach ($items as $ruleKey => $item) {
            $this->assertSame('confirmed', $item->status, "{$ruleKey}: generated items land confirmed");
            $this->assertSame('template', $item->source, "{$ruleKey}: source is template");
            $this->assertSame((string) $version->getKey(), (string) $item->template_version_id, "{$ruleKey}: template version stamped");
            $this->assertSame('America/New_York', $item->timezone, "{$ruleKey}: timezone from inputs");
            $this->assertFalse((bool) $item->date_overridden, "{$ruleKey}: fresh items are not overridden");
            $this->assertSame('2026-10-30', $item->inputs['trigger_date'] ?? null, "{$ruleKey}: inputs stored as provenance");

            $trace = $item->calc_trace;
            $this->assertIsArray($trace, "{$ruleKey}: trace stored");
            $this->assertSame($ruleKey, $trace['rule_key'] ?? null, "{$ruleKey}: trace names the rule");
            $this->assertSame(1, $trace['template_version'] ?? null, "{$ruleKey}: trace names the template version");
            $this->assertSame($item->due_date?->toDateString(), $trace['result_date'] ?? null, "{$ruleKey}: trace result is the stored date");
        }
        $this->assertSame('23:59:00', substr((string) $items['answer']->cutoff_time, 0, 8));
        $this->assertSame('17:00:00', substr((string) $items['initial-disclosures']->cutoff_time, 0, 8));

        // Dependency graph: disclosures depends on discovery-open.
        $this->assertTrue(TaskDependency::query()
            ->where('predecessor_item_id', (string) $items['discovery-open']->getKey())
            ->where('successor_item_id', (string) $items['initial-disclosures']->getKey())
            ->exists(), 'template depends_on becomes a task dependency edge');

        // Checklist from the template rule lands on discovery-open.
        $this->assertTrue(ChecklistItem::query()
            ->where('item_id', (string) $items['discovery-open']->getKey())
            ->where('label', 'Draft discovery plan')
            ->where('required', true)
            ->exists(), 'template checklist becomes checklist items');

        // The answer rule declares a reminder_policy block: it
        // becomes the item's policy v1. The other rules declare none
        // and are skipped silently.
        $answerPolicy = ReminderPolicy::query()
            ->where('item_id', (string) $items['answer']->getKey())
            ->where('active', true)
            ->first();
        $this->assertNotNull($answerPolicy, 'answer rule reminder_policy becomes policy v1');
        $this->assertSame(1, $answerPolicy->version);
        $this->assertCount(2, $answerPolicy->rules);
        $this->assertNull(ReminderPolicy::query()->where('item_id', (string) $items['discovery-open']->getKey())->first());

        // Matter template stamp (009-D20).
        $matterAfter = $matter->fresh();
        $this->assertSame((string) $this->loader->deadlineTemplate('tpl-demo-litigation')->getKey(), (string) $matterAfter?->template_id);
        $this->assertSame(1, $matterAfter?->template_version);

        // Audit: calendar.template.instantiated with batch + count.
        $audit = AuditEvent::query()
            ->where('event', 'calendar.template.instantiated')
            ->where('payload->batch_id', $batchId)
            ->first();
        $this->assertNotNull($audit, 'instantiation is audited with the batch id');
        $this->assertSame(3, $audit->payload['item_count'] ?? null, 'audit payload carries the item count');
        $this->assertSame((string) $version->getKey(), $audit->payload['template_version_id'] ?? null);

        // ── Duplicate confirm with the same key creates nothing. ──
        $duplicate = $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.store', $matter),
            ['template_version_id' => (string) $version->getKey(), 'inputs' => $inputs, 'idempotency_key' => 'c14-confirm-1']
        );
        $duplicate->assertOk()->assertJsonPath('id', $batchId)->assertJsonPath('item_count', 3);
        $this->assertSame(1, InstantiationBatch::count());
        $this->assertSame(3, CalendarItem::query()->where('instantiation_batch_id', $batchId)->count());

        // ── A draft version cannot be instantiated. ──
        $draft = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $this->loader->deadlineTemplate('tpl-demo-litigation')->getKey())
            ->where('version', 2)
            ->firstOrFail();
        $this->assertSame('draft', $draft->status);

        $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.store', $matter),
            ['template_version_id' => (string) $draft->getKey(), 'inputs' => $inputs, 'idempotency_key' => 'c14-draft-1']
        )->assertStatus(422);

        try {
            InstantiationService::confirm($matter, $draft, $inputs, 'c14-draft-2', $attorney);
            $this->fail('confirming a draft version must throw');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
        $this->assertSame(1, InstantiationBatch::count(), 'draft confirm creates no batch');

        // ── Forced mid-creation failure rolls the whole graph back:
        // a published version whose second item title exceeds the
        // column length fails AFTER the batch + first item would
        // have been written in a non-transactional implementation. ──
        $admin = $this->loader->user('user-admin');
        $probeTemplate = TemplateService::createTemplate($attorney, ['name' => 'Rollback Probe']);
        $probeVersion = TemplateService::createVersion($attorney, $probeTemplate, ['rules' => [
            'holiday_pack' => 'ga-state-2026',
            'triggers' => [['key' => 'service_date', 'kind' => 'date', 'label' => 'Service']],
            'items' => [
                ['key' => 'first', 'title' => 'First item', 'type' => 'deadline',
                    'rule' => ['anchor' => 'service_date', 'steps' => [['op' => 'add_calendar_days', 'n' => 1]]]],
                ['key' => 'second', 'title' => str_repeat('X', 501), 'type' => 'deadline',
                    'rule' => ['anchor' => 'service_date', 'steps' => [['op' => 'add_calendar_days', 'n' => 2]]]],
            ],
        ]]);
        TemplateService::submit($attorney, $probeVersion);
        TemplateService::publish($admin, $probeVersion->fresh() ?? $probeVersion);

        $itemCountBeforeProbe = CalendarItem::query()->where('matter_id', (string) $matter->getKey())->count();
        try {
            InstantiationService::confirm($matter, $probeVersion->fresh() ?? $probeVersion, $inputs, 'c14-rollback-1', $attorney);
            $this->fail('an over-long item title must fail creation');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e, 'the failure is mid-creation, not a validation refusal');
        }

        $this->assertSame(0, InstantiationBatch::query()->where('idempotency_key', 'c14-rollback-1')->count(), 'rolled-back confirm leaves no batch');
        $this->assertSame($itemCountBeforeProbe, CalendarItem::query()->where('matter_id', (string) $matter->getKey())->count(), 'rolled-back confirm leaves no partial items');
    }
}
