<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\User;
use App\Services\Calendaring\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_recurrence_edit_scopes(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $anchor = $this->loader->calendarItem('item-series');

        // Convention pinned: the anchor counts as the first occurrence,
        // series_id = anchor id, and the fixture's occurrences:3 yields
        // exactly 3 rows total.
        $this->assertSame((string) $anchor->getKey(), (string) $anchor->fresh()->series_id);
        $series = CalendarItem::query()->where('series_id', $anchor->getKey())
            ->orderBy('occurrence_date')->get();
        $this->assertCount(3, $series);
        $this->assertSame((string) $anchor->getKey(), (string) $series->first()->getKey());
        $dates = $series->map(fn (CalendarItem $row) => $row->occurrence_date?->toDateString())->all();
        $this->assertSame($dates[0], $series[0]->due_date?->toDateString());
        $gap = (int) Carbon::parse($dates[0])->diffInDays(Carbon::parse($dates[1]));
        $this->assertSame(7, $gap, 'weekly interval steps 7 days from the anchor');

        // Materialization is idempotent: re-running creates nothing new.
        RecurrenceService::materialize($anchor->fresh());
        RecurrenceService::materialize($anchor->fresh());
        $this->assertSame(3, CalendarItem::query()->where('series_id', $anchor->getKey())->count());

        // Scope=this: only that occurrence changes (and detaches).
        $second = $series[1];
        $this->actingAs($attorney)->postJson(route('calendar.items.recurrence', $second), [
            'scope' => 'this',
            'title' => 'One-off special check-in',
        ])->assertOk();
        $this->assertSame('One-off special check-in', CalendarItem::findOrFail($second->getKey())->title);
        $this->assertTrue((bool) CalendarItem::findOrFail($second->getKey())->recurrence_detached);
        $this->assertSame($anchor->title, CalendarItem::findOrFail($series[0]->getKey())->title);
        $this->assertSame($anchor->title, CalendarItem::findOrFail($series[2]->getKey())->title);

        // Fresh 4-occurrence series for following/series scopes.
        $custom = $this->makeSeries($attorney, 'Weekly filing review', '2026-11-02', 4);
        [$o1, $o2, $o3, $o4] = $custom->all();

        // Earlier occurrence completed — following edits must not touch it.
        $o1->forceFill(['status' => 'completed', 'task_status' => 'completed'])->save();

        $this->actingAs($attorney)->postJson(route('calendar.items.recurrence', $o2), [
            'scope' => 'following',
            'title' => 'Weekly filing review (moved)',
        ])->assertOk();
        $this->assertSame('Weekly filing review', CalendarItem::findOrFail($o1->getKey())->title, 'earlier completed occurrence untouched');
        $this->assertSame('Weekly filing review (moved)', CalendarItem::findOrFail($o2->getKey())->title);
        $this->assertSame('Weekly filing review (moved)', CalendarItem::findOrFail($o3->getKey())->title);
        $this->assertSame('Weekly filing review (moved)', CalendarItem::findOrFail($o4->getKey())->title);

        // Scope=series from the third occurrence: this + later change,
        // past occurrences (o1, o2) keep their fields, and the rule is
        // updated on the anchor row.
        $newRule = ['freq' => 'weekly', 'interval' => 2, 'byday' => ['MO'], 'count' => 4, 'starts_on' => '2026-11-02'];
        $this->actingAs($attorney)->postJson(route('calendar.items.recurrence', $o3), [
            'scope' => 'series',
            'title' => 'Biweekly filing review',
            'recurrence_rule' => $newRule,
        ])->assertOk();
        $this->assertSame('Weekly filing review', CalendarItem::findOrFail($o1->getKey())->title, 'past occurrence title untouched by series edit');
        $this->assertSame('Weekly filing review (moved)', CalendarItem::findOrFail($o2->getKey())->title, 'past occurrence title untouched by series edit');
        $this->assertSame('Biweekly filing review', CalendarItem::findOrFail($o3->getKey())->title);
        $this->assertSame('Biweekly filing review', CalendarItem::findOrFail($o4->getKey())->title);
        $this->assertSame(2, (int) (CalendarItem::findOrFail($o1->getKey())->recurrence_rule['interval'] ?? 0), 'series edit updates the rule on the anchor');

        // Audit carries the scope.
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.item.updated')
            ->where('payload->item_id', (string) $o3->getKey())
            ->where('payload->scope', 'series')->exists());
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.item.updated')
            ->where('payload->item_id', (string) $second->getKey())
            ->where('payload->scope', 'this')->exists());
    }

    /**
     * @return Collection<int, CalendarItem>
     */
    private function makeSeries(User $actor, string $title, string $start, int $count): Collection
    {
        $matter = $this->loader->matter('matter-1');
        $anchor = CalendarItem::create([
            'org_id' => (string) $matter->org_id,
            'matter_id' => (string) $matter->getKey(),
            'type' => 'task',
            'title' => $title,
            'status' => 'confirmed',
            'task_status' => 'not_started',
            'due_date' => $start,
            'occurrence_date' => $start,
            'timezone' => 'America/New_York',
            'source' => 'manual',
            'recurrence_rule' => ['freq' => 'weekly', 'interval' => 1, 'byday' => ['MO'], 'count' => $count, 'starts_on' => $start],
            'created_by' => (string) $actor->getKey(),
            'updated_by' => (string) $actor->getKey(),
        ]);

        $rows = RecurrenceService::materialize($anchor);
        $this->assertCount($count, $rows);

        return $rows;
    }
}
