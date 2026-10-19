<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\InstantiationBatch;
use App\Models\ReminderDelivery;
use App\Services\Calendaring\InstantiationService;
use App\Services\Calendaring\ItemService;
use App\Services\Calendaring\RecalculationService;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-16 (009 T-13): recalculation — shifting the trigger date first
 * produces an impact preview that persists nothing; applying it
 * supersedes only the affected generated items (old rows retained,
 * linked to their successors, new items carrying shifted dates and
 * fresh traces), preserves a manually overridden item unless
 * reset_overrides is passed, and pending reminder deliveries follow
 * the supersede/materialize flow.
 */
class CalendaringRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
        Artisan::call('calendaring:load-holiday-packs');
    }

    public function test_recalculation_requires_impact_preview_and_preserves_overrides(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $viewer = $this->loader->user('user-viewer');
        $matter = $this->loader->matter('matter-1');

        $version = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $this->loader->deadlineTemplate('tpl-demo-litigation')->getKey())
            ->where('version', 1)
            ->firstOrFail();

        $inputs = [
            'trigger_date' => '2026-10-30',
            'service_date' => '2026-10-30',
            'timezone' => 'America/New_York',
        ];

        $batch = InstantiationService::confirm($matter, $version, $inputs, 'c16-batch-1', $attorney);
        $this->assertInstanceOf(InstantiationBatch::class, $batch);

        $live = fn () => CalendarItem::query()
            ->where('instantiation_batch_id', (string) $batch->getKey())
            ->whereIn('status', ['confirmed', 'at_risk'])
            ->get()
            ->keyBy(fn (CalendarItem $item) => (string) $item->rule_key);

        $items = $live();
        $answer = $items['answer'];
        $discovery = $items['discovery-open'];
        $disclosures = $items['initial-disclosures'];
        $this->assertSame('2026-11-30', $answer->due_date?->toDateString());
        $this->assertSame('2026-12-07', $discovery->due_date?->toDateString());
        $this->assertSame('2026-12-14', $disclosures->due_date?->toDateString());

        // Reminder setup on the answer item: give it an assignee and
        // a policy whose deliveries actually materialize, so the
        // recalculation's reminder consequences are observable.
        $answer->forceFill(['assignee_id' => (string) $attorney->getKey()])->save();
        ReminderPlanner::setPolicy($answer->fresh() ?? $answer, [[
            'offset_minutes' => -1440,
            'channels' => ['in_app'],
            'recipients' => ['assignee'],
            'criticality' => 'mandatory',
            'label' => '1 day before',
        ]], $attorney);

        $oldPending = ReminderDelivery::query()
            ->where('item_id', (string) $answer->getKey())
            ->where('status', 'pending')
            ->get();
        $this->assertCount(1, $oldPending, 'answer item starts with one pending delivery');
        $oldBase = ReminderPlanner::baseInstant($answer->fresh() ?? $answer);
        $this->assertNotNull($oldBase);
        $this->assertSame($oldBase->copy()->subDay()->toIso8601String(), $oldPending->first()?->scheduled_for?->toIso8601String());

        // Manual override: move discovery-open's date through the
        // user edit path — ItemService marks it overridden.
        $overridden = ItemService::update($discovery->fresh() ?? $discovery, [
            'due_date' => '2026-12-10',
            'version' => (int) $discovery->version,
        ], $attorney);
        $this->assertSame('2026-12-10', $overridden->due_date?->toDateString());
        $this->assertTrue((bool) $overridden->fresh()?->date_overridden, 'a user date edit on a template item marks date_overridden');

        // ── Preview: trigger +7 days ⇒ the chain shifts; nothing
        // persists; the overridden item is reported preserved. ──
        $newInputs = [
            'trigger_date' => '2026-11-06',
            'service_date' => '2026-11-06',
            'timezone' => 'America/New_York',
        ];

        $itemCountBefore = CalendarItem::query()->where('instantiation_batch_id', (string) $batch->getKey())->count();

        $preview = RecalculationService::preview($batch, $newInputs);
        $diff = [];
        foreach ($preview['entries'] as $entry) {
            $diff[(string) $entry['rule_key']] = $entry;
        }

        $this->assertSame('2026-11-30', $diff['answer']['old_date']);
        $this->assertSame('2026-12-07', $diff['answer']['new_date'], 'answer: 2026-11-06 + 30d = Sun Dec 6, next court day Mon Dec 7');
        $this->assertTrue($diff['answer']['changed']);
        $this->assertFalse($diff['answer']['preserved']);

        $this->assertSame('2026-12-14', $diff['initial-disclosures']['old_date']);
        $this->assertSame('2026-12-21', $diff['initial-disclosures']['new_date'], 'disclosures: new answer + 10 business days');
        $this->assertTrue($diff['initial-disclosures']['changed']);

        $this->assertSame('2026-12-10', $diff['discovery-open']['old_date'], 'diff reports the overridden (current) date');
        $this->assertSame('2026-12-14', $diff['discovery-open']['new_date']);
        $this->assertTrue($diff['discovery-open']['changed']);
        $this->assertTrue($diff['discovery-open']['date_overridden']);
        $this->assertTrue($diff['discovery-open']['preserved'], 'overridden items are preserved unless reset_overrides is passed');

        // Preview persisted nothing.
        $this->assertSame($itemCountBefore, CalendarItem::query()->where('instantiation_batch_id', (string) $batch->getKey())->count());
        $this->assertSame('confirmed', CalendarItem::findOrFail((string) $answer->getKey())->status);
        $this->assertSame('2026-10-30', InstantiationBatch::findOrFail((string) $batch->getKey())->inputs['trigger_date'] ?? null);

        // HTTP preview agrees and is edit-gated.
        $httpPreview = $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.recalculate.preview', [$matter, $batch]),
            ['trigger_date' => '2026-11-06']
        );
        $httpPreview->assertOk();
        $httpEntries = collect($httpPreview->json('entries'))->keyBy('rule_key');
        $this->assertSame('2026-12-07', $httpEntries['answer']['new_date'] ?? null);
        $this->assertTrue($httpEntries['discovery-open']['preserved'] ?? null);

        $this->actingAs($viewer)->postJson(
            route('calendar.instantiations.recalculate.preview', [$matter, $batch]),
            ['trigger_date' => '2026-11-06']
        )->assertNotFound();
        $this->assertSame($itemCountBefore, CalendarItem::query()->where('instantiation_batch_id', (string) $batch->getKey())->count(), 'HTTP preview also persists nothing');

        // ── Apply (no reset): affected items supersede, the
        // overridden item survives untouched. ──
        $result = RecalculationService::apply($batch, $newInputs, $attorney);
        $this->assertCount(2, $result['successors']);
        $this->assertCount(1, $result['preserved']);
        $this->assertSame((string) $discovery->getKey(), (string) $result['preserved'][0]->getKey());

        // Old rows retained, status superseded, original dates intact.
        $answerOld = CalendarItem::findOrFail((string) $answer->getKey());
        $this->assertSame('superseded', $answerOld->status);
        $this->assertSame('2026-11-30', $answerOld->due_date?->toDateString(), 'superseded rows keep their original date (history is not rewritten)');
        $this->assertNotNull($answerOld->superseded_by_id);

        $disclosuresOld = CalendarItem::findOrFail((string) $disclosures->getKey());
        $this->assertSame('superseded', $disclosuresOld->status);
        $this->assertSame('2026-12-14', $disclosuresOld->due_date?->toDateString());

        // Successors carry the shifted dates, fresh traces, the batch.
        $after = $live();
        $this->assertCount(3, $after);
        $newAnswer = $after['answer'];
        $newDisclosures = $after['initial-disclosures'];
        $this->assertSame('2026-12-07', $newAnswer->due_date?->toDateString());
        $this->assertSame('2026-12-21', $newDisclosures->due_date?->toDateString());
        $this->assertSame((string) $answerOld->getKey(), (string) $newAnswer->supersedes_id, 'successor links back to its predecessor');
        $this->assertSame((string) $newAnswer->getKey(), (string) $answerOld->superseded_by_id, 'predecessor links forward to its successor');
        $this->assertSame('2026-11-06', $newAnswer->inputs['trigger_date'] ?? null);
        $this->assertFalse((bool) $newAnswer->date_overridden);
        $trace = $newAnswer->calc_trace;
        $this->assertIsArray($trace);
        $this->assertSame('2026-12-07', $trace['result_date'] ?? null, 'successor carries a fresh trace for its new date');
        $this->assertSame(1, $trace['template_version'] ?? null);

        // The overridden item is NOT superseded and keeps its date.
        $discoveryAfter = CalendarItem::findOrFail((string) $discovery->getKey());
        $this->assertSame('confirmed', $discoveryAfter->status);
        $this->assertSame('2026-12-10', $discoveryAfter->due_date?->toDateString());
        $this->assertNull($discoveryAfter->superseded_by_id);

        // Batch inputs now hold the new trigger facts.
        $this->assertSame('2026-11-06', InstantiationBatch::findOrFail((string) $batch->getKey())->inputs['trigger_date'] ?? null);

        // Audits: per-item superseded + the batch recalculation.
        $this->assertTrue(AuditEvent::query()
            ->where('event', 'calendar.item.superseded')
            ->where('payload->item_id', (string) $answer->getKey())
            ->exists(), 'supersede is audited per item');
        $recalcAudit = AuditEvent::query()
            ->where('event', 'calendar.template.recalculated')
            ->where('payload->batch_id', (string) $batch->getKey())
            ->first();
        $this->assertNotNull($recalcAudit, 'the recalculation is audited');
        $this->assertSame(2, $recalcAudit->payload['superseded_count'] ?? null);
        $this->assertSame(1, $recalcAudit->payload['preserved_count'] ?? null);

        // Reminder flow: the old item's pending delivery is no longer
        // pending (superseded by the lifecycle hook); the successor
        // materialized a fresh pending delivery at the new date.
        $this->assertSame('superseded', ReminderDelivery::findOrFail((string) $oldPending->first()?->getKey())->status);

        $newPending = ReminderDelivery::query()
            ->where('item_id', (string) $newAnswer->getKey())
            ->where('status', 'pending')
            ->get();
        $this->assertCount(1, $newPending, 'successor materializes exactly one pending delivery from the carried policy');
        $newBase = ReminderPlanner::baseInstant($newAnswer);
        $this->assertNotNull($newBase);
        $this->assertSame($newBase->copy()->subDay()->toIso8601String(), $newPending->first()?->scheduled_for?->toIso8601String());

        // ── Apply again via HTTP with reset_overrides=true: the
        // overridden item now supersedes too. ──
        $newerInputs = [
            'trigger_date' => '2026-11-13',
            'service_date' => '2026-11-13',
            'timezone' => 'America/New_York',
        ];

        $resetPreview = RecalculationService::preview($batch->fresh() ?? $batch, $newerInputs, resetOverrides: true);
        $resetDiff = [];
        foreach ($resetPreview['entries'] as $entry) {
            $resetDiff[(string) $entry['rule_key']] = $entry;
        }
        $this->assertFalse($resetDiff['discovery-open']['preserved'], 'with reset_overrides the overridden item is no longer preserved');
        $expectedDiscoveryDate = $resetDiff['discovery-open']['new_date'];
        $this->assertIsString($expectedDiscoveryDate);

        $applyResponse = $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.recalculate', [$matter, $batch]),
            ['trigger_date' => '2026-11-13', 'reset_overrides' => true]
        );
        $applyResponse->assertOk();

        $discoveryOldAfter = CalendarItem::findOrFail((string) $discovery->getKey());
        $this->assertSame('superseded', $discoveryOldAfter->status, 'reset_overrides supersedes the manually overridden item');
        $this->assertSame('2026-12-10', $discoveryOldAfter->due_date?->toDateString(), 'its history row still keeps the overridden date');

        $final = $live();
        $this->assertCount(3, $final);
        $this->assertSame($expectedDiscoveryDate, $final['discovery-open']->due_date?->toDateString(), 'successor takes the recalculated date');
        $this->assertFalse((bool) $final['discovery-open']->date_overridden, 'the successor is a fresh calculated item, not an override');
        $this->assertSame((string) $discovery->getKey(), (string) $final['discovery-open']->supersedes_id);
        $this->assertSame('2026-11-13', InstantiationBatch::findOrFail((string) $batch->getKey())->inputs['trigger_date'] ?? null);
    }
}
