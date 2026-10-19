<?php

namespace Tests\Feature;

use App\Models\ReminderDelivery;
use App\Models\ReminderPreference;
use App\Services\Calendaring\ItemService;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringRemindersTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_reminder_offsets_recalculate_on_date_change(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $item = $this->loader->calendarItem('item-hard-deadline');

        // Fixture preference wiring (T-06).
        $pref = ReminderPreference::query()->where('user_id', $this->loader->user('user-paralegal')->getKey())->first();
        $this->assertNotNull($pref);
        $this->assertStringStartsWith('22:00', (string) $pref->quiet_start);

        // Materialize (fixture loader also materializes; this call is
        // idempotent and guarantees the starting state for the test).
        ReminderPlanner::materialize($item->fresh() ?? $item);

        $base = ReminderPlanner::baseInstant($item);
        $this->assertNotNull($base);

        $pending = ReminderDelivery::query()
            ->where('item_id', $item->getKey())
            ->where('status', 'pending')
            ->orderBy('scheduled_for')
            ->get();

        // Rules: -7d (in_app+email, assignee) and -1d (in_app,
        // assignee+owner). Owner = attorney (direct owner grant);
        // assignee = attorney as well, so recipients dedupe to one
        // user: 2 channels at -7d + 1 in_app at -1d = 3 rows, at two
        // distinct instants.
        $instants = $pending->map(fn (ReminderDelivery $d) => $d->scheduled_for?->toIso8601String())->unique()->values();
        $this->assertCount(2, $instants);
        $this->assertSame($base->copy()->subDays(7)->toIso8601String(), $instants[0]);
        $this->assertSame($base->copy()->subDays(1)->toIso8601String(), $instants[1]);

        $atMinus7 = $pending->filter(fn (ReminderDelivery $d) => $d->scheduled_for?->toIso8601String() === $base->copy()->subDays(7)->toIso8601String());
        $this->assertEqualsCanonicalizing(['email', 'in_app'], $atMinus7->pluck('channel')->all());

        // Mark one old delivery sent; it must survive byte-identical.
        $sent = $pending->first();
        $this->assertNotNull($sent);
        $sent->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_ref' => 'test-sent-ref',
            'attempts' => 1,
        ])->save();
        $sentSnapshot = ReminderDelivery::findOrFail($sent->getKey())->getAttributes();

        $oldPendingIds = $pending->reject(fn (ReminderDelivery $d) => (string) $d->getKey() === (string) $sent->getKey())
            ->map(fn (ReminderDelivery $d) => (string) $d->getKey())->all();

        // Move the due date +3 days via ItemService (hard deadline:
        // reason required).
        $newDue = Carbon::parse((string) $item->due_date?->toDateString())->addDays(3)->toDateString();
        $updated = ItemService::update($item->fresh() ?? $item, [
            'due_date' => $newDue,
            'version' => (int) $item->version,
            'reason' => 'Court moved the deadline',
        ], $attorney);
        $this->assertSame($newDue, $updated->due_date?->toDateString());

        // Old pending rows are superseded.
        foreach ($oldPendingIds as $id) {
            $this->assertSame('superseded', ReminderDelivery::findOrFail($id)->status);
        }

        // The sent row remains sent and byte-identical.
        $sentAfter = ReminderDelivery::findOrFail($sent->getKey());
        $this->assertSame('sent', $sentAfter->status);
        $this->assertSame($sentSnapshot, $sentAfter->getAttributes());

        // New pending rows exist at new-7d and new-1d.
        $newBase = ReminderPlanner::baseInstant($updated->fresh() ?? $updated);
        $this->assertNotNull($newBase);
        $newPending = ReminderDelivery::query()
            ->where('item_id', $item->getKey())
            ->where('status', 'pending')
            ->orderBy('scheduled_for')
            ->get();
        $newInstants = $newPending->map(fn (ReminderDelivery $d) => $d->scheduled_for?->toIso8601String())->unique()->values();
        $this->assertCount(2, $newInstants);
        $this->assertSame($newBase->copy()->subDays(7)->toIso8601String(), $newInstants[0]);
        $this->assertSame($newBase->copy()->subDays(1)->toIso8601String(), $newInstants[1]);
        foreach ($newPending as $row) {
            $this->assertNotContains((string) $row->getKey(), $oldPendingIds);
        }
    }
}
