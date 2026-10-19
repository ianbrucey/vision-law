<?php

namespace Tests\Feature;

use App\Models\ReminderDelivery;
use App\Services\Calendaring\AgendaQuery;
use App\Services\Calendaring\ItemService;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-18 (009 T-15, 009-D23): time-zone correctness end to end over
 * the fixture DST set at the 2026-11-01 America/New_York fall-back.
 */
class CalendaringTimezonesTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_timezone_and_dst_consistency(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $matter1 = $this->loader->matter('matter-1');
        $dst = $this->loader->calendarItem('item-event-dst');

        // --- Stored UTC instants equal the fixtures' expected values --
        // Fixture: 2026-11-01 09:00–11:00 America/New_York, after the
        // 02:00 fall-back, so EST (UTC-5): 14:00Z / 16:00Z.
        $this->assertSame('2026-11-01T14:00:00+00:00', $dst->starts_at?->copy()->setTimezone('UTC')->toIso8601String());
        $this->assertSame('2026-11-01T16:00:00+00:00', $dst->ends_at?->copy()->setTimezone('UTC')->toIso8601String());
        $this->assertSame('America/New_York', $dst->timezone);

        // --- Rendering in the originating zone shows the wall clock ---
        $show = $this->actingAs($attorney)->get(route('calendar.items.show', $dst));
        $show->assertOk();
        $show->assertSee('Nov 1, 2026 9:00 AM');
        $show->assertSee('Nov 1, 2026 11:00 AM');
        $show->assertSee('America/New_York');

        // The same instant rendered for America/Los_Angeles is 06:00
        // PST — one instant, two wall clocks.
        $this->assertSame(
            '2026-11-01 06:00',
            $dst->starts_at?->copy()->setTimezone('America/Los_Angeles')->format('Y-m-d H:i')
        );
        $this->assertSame(
            '2026-11-01 09:00',
            $dst->starts_at?->copy()->setTimezone('America/New_York')->format('Y-m-d H:i')
        );

        // ICS export agrees on the instant (T-14 surface, UTC form).
        $export = (string) $this->actingAs($attorney)->get(route('calendar.export'))->assertOk()->getContent();
        $this->assertStringContainsString('DTSTART:20261101T140000Z', $export);
        $this->assertStringContainsString('DTEND:20261101T160000Z', $export);

        // --- Repeated hour: ambiguous 01:30 resolves to its first -----
        // occurrence (EDT, UTC-4) = 05:30Z, and renders back as 01:30
        // in America/New_York. 02:30 is unambiguous EST = 07:30Z, so
        // the event really spans two elapsed hours.
        $ambiguous = ItemService::create($matter1, [
            'type' => 'event',
            'title' => 'Fall-back hearing window',
            'starts_at' => '2026-11-01T01:30:00',
            'ends_at' => '2026-11-01T02:30:00',
            'timezone' => 'America/New_York',
            'status' => 'confirmed',
        ], $attorney);
        $this->assertSame('2026-11-01T05:30:00+00:00', $ambiguous->starts_at?->copy()->setTimezone('UTC')->toIso8601String());
        $this->assertSame('2026-11-01T07:30:00+00:00', $ambiguous->ends_at?->copy()->setTimezone('UTC')->toIso8601String());
        $this->assertSame(
            '01:30',
            $ambiguous->starts_at?->copy()->setTimezone('America/New_York')->format('H:i')
        );

        // --- Agenda groupings follow the viewer's zone -----------------
        // 2026-11-01T03:30Z is Oct 31 23:30 in New York (EDT) and
        // Oct 31 20:30 in Los Angeles (PDT), but Nov 1 in UTC.
        $late = ItemService::create($matter1, [
            'type' => 'event',
            'title' => 'Late night filing call',
            'starts_at' => '2026-11-01T03:30:00Z',
            'ends_at' => '2026-11-01T04:00:00Z',
            'timezone' => 'America/New_York',
            'status' => 'confirmed',
        ], $attorney);

        foreach (['America/New_York' => '2026-10-31', 'America/Los_Angeles' => '2026-10-31', 'UTC' => '2026-11-01'] as $tz => $expectedDate) {
            $agenda = $this->actingAs($attorney)->getJson(route('calendar.agenda', ['mode' => 'list', 'tz' => $tz]));
            $agenda->assertOk();
            $row = collect($agenda->json('items'))->firstWhere('id', (string) $late->getKey());
            $this->assertNotNull($row, "late event missing from agenda ({$tz})");
            $this->assertSame($expectedDate, $row['effective_date'], "grouping date in {$tz}");
        }

        // The day window follows the viewer's zone too: the late event
        // is inside Oct 31 for a New York viewer and outside it in UTC.
        $nyDay = $this->actingAs($attorney)->getJson(route('calendar.agenda', ['mode' => 'day', 'anchor' => '2026-10-31', 'tz' => 'America/New_York']));
        $this->assertContains((string) $late->getKey(), collect($nyDay->json('items'))->pluck('id')->all());
        $utcDay = $this->actingAs($attorney)->getJson(route('calendar.agenda', ['mode' => 'day', 'anchor' => '2026-10-31', 'tz' => 'UTC']));
        $this->assertNotContains((string) $late->getKey(), collect($utcDay->json('items'))->pluck('id')->all());

        // The DST fixture event groups on Nov 1 in both US zones.
        foreach (['America/New_York', 'America/Los_Angeles'] as $tz) {
            $result = AgendaQuery::forUser($attorney, 'list', null, [], $tz);
            $row = $result['items']->firstWhere('id', (string) $dst->getKey());
            $this->assertNotNull($row);
            $this->assertSame('2026-11-01', AgendaQuery::effectiveDate($row, $tz));
        }

        // --- Date-only legal due dates never shift ---------------------
        $deadline = ItemService::create($matter1, [
            'type' => 'deadline',
            'title' => 'DST weekend filing deadline',
            'classification' => 'hard',
            'jurisdiction' => 'GA Superior Court (DEMONSTRATION)',
            'due_date' => '2026-11-01',
            'cutoff_time' => '17:00',
            'timezone' => 'America/New_York',
            'status' => 'confirmed',
        ], $attorney);

        foreach (['America/New_York', 'America/Los_Angeles', 'UTC', 'Pacific/Auckland'] as $tz) {
            $result = AgendaQuery::forUser($attorney, 'list', null, [], $tz);
            $row = $result['items']->firstWhere('id', (string) $deadline->getKey());
            $this->assertNotNull($row, "deadline missing in {$tz}");
            $this->assertSame('2026-11-01', AgendaQuery::effectiveDate($row, $tz), "due date shifted in {$tz}");
            $this->assertSame('2026-11-01', $row->due_date?->toDateString());
        }

        // Its reminder base instant resolves in the jurisdiction zone:
        // 2026-11-01 17:00 EST = 22:00Z.
        $this->assertSame(
            '2026-11-01T22:00:00+00:00',
            ReminderPlanner::baseInstant($deadline)?->toIso8601String()
        );

        // ICS renders it as a date, identical from any viewer zone.
        $export = (string) $this->actingAs($attorney)->get(route('calendar.export'))->assertOk()->getContent();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261101', $export);

        // --- DST-boundary reminder fires exactly once ------------------
        // Fixture policy: attendees, in_app, 1 hour before → 13:00Z.
        $deliveries = ReminderDelivery::query()
            ->where('item_id', (string) $dst->getKey())
            ->where('kind', 'reminder')
            ->get();
        $this->assertCount(2, $deliveries, 'one delivery per attendee recipient');
        foreach ($deliveries as $delivery) {
            $this->assertSame('2026-11-01T13:00:00+00:00', $delivery->scheduled_for?->copy()->setTimezone('UTC')->toIso8601String());
            $this->assertSame('in_app', $delivery->channel);
        }
        $this->assertSame(
            2,
            $deliveries->pluck('idempotency_key')->unique()->count(),
            'idempotency keys must be unique per recipient'
        );

        // Re-materialization is idempotent: still exactly one each.
        ReminderPlanner::materialize($dst->fresh());
        $this->assertSame(
            2,
            ReminderDelivery::query()->where('item_id', (string) $dst->getKey())->where('kind', 'reminder')->count()
        );
    }
}
