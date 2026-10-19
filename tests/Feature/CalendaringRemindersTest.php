<?php

namespace Tests\Feature;

use App\Models\MatterGrant;
use App\Models\ReminderDelivery;
use App\Models\ReminderPreference;
use App\Notifications\ReminderNotification;
use App\Services\Calendaring\Channels\EmailChannel;
use App\Services\Calendaring\Channels\ReminderChannel;
use App\Services\Calendaring\ItemService;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

    public function test_reminder_delivery_resolves_recipients_and_ledgers_attempts(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $item = $this->loader->calendarItem('item-hard-deadline');
        $matter = $this->loader->matter('matter-1');

        ReminderPlanner::materialize($item->fresh() ?? $item);

        // (a) Remove the assignee's matter grant, make rows due, run
        // dispatch: deliveries are suppressed at send time and the
        // user receives zero notifications.
        MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->where('user_id', $attorney->getKey())
            ->update(['expires_at' => now()->subMinute()]);

        ReminderDelivery::query()->where('item_id', $item->getKey())
            ->where('status', 'pending')
            ->update(['scheduled_for' => now()->subMinute()]);

        Artisan::call('reminders:dispatch');

        $suppressed = ReminderDelivery::query()->where('item_id', $item->getKey())->get();
        $this->assertNotEmpty($suppressed);
        foreach ($suppressed as $row) {
            $this->assertSame('suppressed_no_access', $row->status, 'channel '.$row->channel);
        }
        $this->assertSame(0, $attorney->notifications()->count());

        // Restore the grant for the following phases.
        MatterGrant::query()
            ->where('matter_id', $matter->getKey())
            ->where('user_id', $attorney->getKey())
            ->update(['expires_at' => null]);

        // (b) A throwing email double + email enabled: the email row
        // records a failed attempt while the in_app row for the same
        // rule still sends.
        $soft = $this->loader->calendarItem('item-soft-deadline');
        ReminderPlanner::setPolicy($soft, [[
            'offset_minutes' => 0,
            'channels' => ['in_app', 'email'],
            'recipients' => ['assignee'],
            'criticality' => 'standard',
            'label' => 'Due now',
        ]], $attorney);

        config(['calendaring.email_enabled' => true]);
        app()->instance('calendaring.channel.email', new class implements ReminderChannel
        {
            public function send(ReminderDelivery $delivery): string
            {
                throw new \RuntimeException('SMTP boom');
            }
        });

        ReminderDelivery::query()->where('item_id', $soft->getKey())
            ->where('status', 'pending')
            ->update(['scheduled_for' => now()->subMinute()]);

        Artisan::call('reminders:dispatch');

        $emailRow = ReminderDelivery::query()->where('item_id', $soft->getKey())->where('channel', 'email')->firstOrFail();
        $this->assertSame((string) $paralegal->getKey(), (string) $emailRow->recipient_user_id);
        $this->assertSame($paralegal->email, $emailRow->recipient_email);
        $this->assertSame(1, (int) $emailRow->attempts);
        $this->assertNotNull($emailRow->last_error);
        $this->assertStringContainsString('SMTP boom', (string) $emailRow->last_error);
        $this->assertSame('pending', $emailRow->status);
        $this->assertNotNull($emailRow->next_attempt_at);
        $this->assertGreaterThan(now()->addSeconds(30), $emailRow->next_attempt_at);
        $this->assertNotNull($emailRow->created_at);
        $this->assertNotNull($emailRow->updated_at);

        $inAppRow = ReminderDelivery::query()->where('item_id', $soft->getKey())->where('channel', 'in_app')->firstOrFail();
        $this->assertSame('sent', $inAppRow->status);
        $this->assertNotNull($inAppRow->sent_at);
        $this->assertNotNull($inAppRow->provider_ref);
        $reminderNotifications = $paralegal->notifications()->where('type', ReminderNotification::class)->get();
        $this->assertCount(1, $reminderNotifications);
        $this->assertSame((string) $inAppRow->getKey(), $reminderNotifications->first()->data['delivery_id'] ?? null);

        // Inbox surface lists the sent in_app delivery.
        $this->actingAs($paralegal)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Draft expert report (internal target)');

        // (c) Email disabled: a fresh policy version creates a fresh
        // email delivery, which lands failed_unconfigured with zero
        // mail sent.
        ReminderPlanner::setPolicy($soft->fresh() ?? $soft, [[
            'offset_minutes' => -120,
            'channels' => ['in_app', 'email'],
            'recipients' => ['assignee'],
            'criticality' => 'standard',
            'label' => 'Two hours before',
        ]], $attorney);

        config(['calendaring.email_enabled' => false]);
        app()->instance('calendaring.channel.email', new EmailChannel);
        Mail::fake();

        ReminderDelivery::query()->where('item_id', $soft->getKey())
            ->where('status', 'pending')
            ->update(['scheduled_for' => now()->subMinute()]);

        Artisan::call('reminders:dispatch');

        $unconfigured = ReminderDelivery::query()->where('item_id', $soft->getKey())
            ->where('channel', 'email')->where('status', 'failed_unconfigured')->first();
        $this->assertNotNull($unconfigured);
        $this->assertNotNull($unconfigured->last_error);
        Mail::assertNothingSent();
    }
}
