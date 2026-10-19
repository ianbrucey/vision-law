<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Services\Calendaring\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringItemsTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_calendar_item_crud_validates_and_conflicts(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $matter = $this->loader->matter('matter-1');

        // Invalid range → 422.
        $this->actingAs($attorney)->postJson(route('calendar.items.store', $matter), [
            'type' => 'event',
            'title' => 'Bad range event',
            'starts_at' => '2026-11-02T10:00:00Z',
            'ends_at' => '2026-11-02T09:00:00Z',
            'timezone' => 'UTC',
        ])->assertStatus(422);

        // Create a valid item.
        $create = $this->actingAs($attorney)->postJson(route('calendar.items.store', $matter), [
            'type' => 'deadline',
            'title' => 'Original title',
            'status' => 'draft',
            'due_date' => '2026-12-01',
            'timezone' => 'America/New_York',
        ]);
        $create->assertCreated();
        $id = $create->json('id');
        $this->assertIsString($id);

        // First editor saves (version 1 → 2).
        $this->actingAs($attorney)->patchJson(route('calendar.items.update', $id), [
            'title' => 'Intervening change',
            'version' => 1,
        ])->assertOk()->assertJsonPath('title', 'Intervening change');

        // Second editor with the stale version → conflict surfacing the
        // intervening change, no silent overwrite.
        $conflict = $this->actingAs($attorney)->patchJson(route('calendar.items.update', $id), [
            'title' => 'Stale overwrite attempt',
            'version' => 1,
        ]);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('current.title', 'Intervening change');
        $conflict->assertJsonPath('current.version', 2);

        $this->assertSame('Intervening change', CalendarItem::findOrFail($id)->title);
    }

    public function test_item_lifecycle_never_rewrites_history(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $item = $this->loader->calendarItem('item-hard-deadline');
        $originalDue = $item->due_date?->toDateString();
        $originalTrace = $item->calc_trace;
        $originalTitle = $item->title;

        $successor = ItemService::supersede($item, [
            'due_date' => '2027-01-15',
            'title' => $originalTitle.' (recalculated)',
        ], $attorney, 'Court order moved the date');

        $original = CalendarItem::findOrFail($item->getKey());
        $this->assertSame('superseded', $original->status);
        $this->assertSame($originalDue, $original->due_date?->toDateString());
        $this->assertSame($originalTitle, $original->title);
        $this->assertSame($originalTrace, $original->calc_trace);
        $this->assertSame((string) $successor->getKey(), (string) $original->superseded_by_id);
        $this->assertSame((string) $original->getKey(), (string) $successor->supersedes_id);
        $this->assertSame('2027-01-15', $successor->due_date?->toDateString());

        // Original remains readable via its show page.
        $this->actingAs($attorney)
            ->get(route('calendar.items.show', $original))
            ->assertOk()
            ->assertSee($originalTitle);

        // Only confirmed items count as reminder-driving.
        $confirmedIds = CalendarItem::query()->confirmed()->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->assertNotContains((string) $original->getKey(), $confirmedIds);
        $this->assertContains((string) $successor->getKey(), $confirmedIds, 'Successor inherits confirmed status');

        // Lifecycle transitions via HTTP: complete then the audit trail exists.
        $soft = $this->loader->calendarItem('item-soft-deadline');
        $this->actingAs($attorney)->postJson(route('calendar.items.complete', $soft))->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.item.completed')
            ->where('payload->item_id', (string) $soft->getKey())->exists());

        // Cancel requires a reason.
        $this->actingAs($attorney)->postJson(route('calendar.items.cancel', $successor))->assertStatus(422);
        $this->actingAs($attorney)->postJson(route('calendar.items.cancel', $successor), ['reason' => 'Settled'])
            ->assertOk()->assertJsonPath('status', 'canceled');
    }

    public function test_hard_deadline_edit_requires_reason_and_is_explainable(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $item = $this->loader->calendarItem('item-hard-deadline');
        $newDate = $item->due_date?->copy()->addDays(7)->toDateString();

        // Without reason → 422.
        $this->actingAs($attorney)->patchJson(route('calendar.items.update', $item), [
            'due_date' => $newDate,
            'version' => $item->version,
        ])->assertStatus(422);
        $this->assertSame($item->due_date?->toDateString(), CalendarItem::findOrFail($item->getKey())->due_date?->toDateString());

        // With reason → succeeds, audit has before/after + reason.
        $fresh = CalendarItem::findOrFail($item->getKey());
        $this->actingAs($attorney)->patchJson(route('calendar.items.update', $item), [
            'due_date' => $newDate,
            'version' => $fresh->version,
            'reason' => 'Judge granted extension',
        ])->assertOk()->assertJsonPath('due_date', $newDate);

        $audit = AuditEvent::query()->where('event', 'calendar.item.updated')
            ->where('payload->item_id', (string) $item->getKey())
            ->orderByDesc('created_at')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Judge granted extension', $audit->payload['reason'] ?? null);
        $this->assertSame($item->due_date?->toDateString(), $audit->payload['before']['due_date'] ?? null);
        $this->assertSame($newDate, $audit->payload['after']['due_date'] ?? null);

        // Explain: an item with a stored calc_trace reproduces anchor/rule/steps.
        $traced = CalendarItem::create([
            'org_id' => (string) $item->org_id,
            'matter_id' => (string) $item->matter_id,
            'type' => 'deadline',
            'title' => 'Traced deadline',
            'status' => 'confirmed',
            'classification' => 'hard',
            'due_date' => '2026-11-30',
            'timezone' => 'America/New_York',
            'source' => 'template',
            'rule_key' => 'answer',
            'calc_trace' => [
                'source' => 'template',
                'anchor' => 'service_date=2026-10-30',
                'rule_key' => 'answer',
                'rule_version' => 'ga-demo-v1',
                'steps' => [
                    ['op' => 'add_calendar_days', 'n' => 30, 'description' => 'Add 30 calendar days'],
                    ['op' => 'adjust', 'mode' => 'next_court_day', 'description' => 'Adjust to next court day'],
                ],
            ],
            'created_by' => (string) $attorney->getKey(),
        ]);

        $explain = $this->actingAs($attorney)->getJson(route('calendar.items.explain', $traced));
        $explain->assertOk();
        $explain->assertJsonPath('rule_key', 'answer');
        $explain->assertJsonPath('anchor', 'service_date=2026-10-30');
        $this->assertSame('ga-demo-v1', $explain->json('rule_version') ?? $explain->json('pack_version'));
        $this->assertCount(2, $explain->json('steps'));

        // Manual item explanation is never empty.
        $manual = ItemService::explain($item->fresh());
        $this->assertSame('manual', $manual['source']);
        $this->assertNotEmpty($manual['steps']);
        $this->assertStringContainsString('Manual entry', (string) $manual['summary']);

        // The show page renders the explanation block.
        $this->actingAs($attorney)->get(route('calendar.items.explain', $traced))
            ->assertOk()->assertSee('Explanation');
    }
}
