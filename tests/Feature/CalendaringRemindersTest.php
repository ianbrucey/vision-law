<?php

namespace Tests\Feature;

use App\Mail\ReminderMail;
use App\Models\AuditEvent;
use App\Models\ItemAttendee;
use App\Models\MatterGrant;
use App\Models\ReminderDelivery;
use App\Models\ReminderPreference;
use App\Notifications\ReminderNotification;
use App\Services\Calendaring\Channels\EmailChannel;
use App\Services\Calendaring\Channels\ReminderChannel;
use App\Services\Calendaring\DeliveryService;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_item_confirm_cancel_and_material_date_notices_use_the_ledger(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $draft = $this->loader->calendarItem('item-draft-deadline');
        $hard = $this->loader->calendarItem('item-hard-deadline');
        $soft = $this->loader->calendarItem('item-soft-deadline');

        // Confirm: assignee + attendees are notified via the ledger.
        $draft->forceFill(['assignee_id' => (string) $paralegal->getKey()])->save();
        ItemAttendee::create(['item_id' => (string) $draft->getKey(), 'user_id' => (string) $attorney->getKey()]);
        ItemService::transition($draft->fresh() ?? $draft, 'confirm', $attorney);

        $confirmNotices = ReminderDelivery::query()->where('item_id', $draft->getKey())
            ->where('kind', 'notice')->where('payload->notice_type', 'confirmed')->get();
        $this->assertCount(2, $confirmNotices);
        foreach ($confirmNotices as $notice) {
            $this->assertSame('sent', $notice->status);
            $this->assertSame('mandatory', $notice->payload['criticality'] ?? null);
            $this->assertSame($attorney->name, $notice->payload['actor_name'] ?? null);
            $this->assertSame('draft', $notice->payload['before']['status'] ?? null);
            $this->assertSame('confirmed', $notice->payload['after']['status'] ?? null);
        }
        $this->assertEqualsCanonicalizing(
            [(string) $paralegal->getKey(), (string) $attorney->getKey()],
            $confirmNotices->map(fn (ReminderDelivery $d) => (string) $d->recipient_user_id)->all()
        );

        // Material date change on a confirmed item: before/after + actor.
        $newDue = Carbon::parse((string) $hard->due_date?->toDateString())->addDay()->toDateString();
        ItemService::update($hard->fresh() ?? $hard, [
            'due_date' => $newDue,
            'version' => (int) ($hard->fresh()?->version ?? $hard->version),
            'reason' => 'Court moved the deadline',
        ], $attorney);

        $dateNotices = ReminderDelivery::query()->where('item_id', $hard->getKey())
            ->where('kind', 'notice')->where('payload->notice_type', 'material_date_change')->get();
        $this->assertCount(1, $dateNotices);
        $dateNotice = $dateNotices->first();
        $this->assertNotNull($dateNotice);
        $this->assertSame('sent', $dateNotice->status);
        $this->assertSame($hard->due_date?->toDateString(), $dateNotice->payload['before']['due_date'] ?? null);
        $this->assertSame($newDue, $dateNotice->payload['after']['due_date'] ?? null);
        $this->assertSame($attorney->name, $dateNotice->payload['actor_name'] ?? null);

        // Cancel: assignee is notified via the ledger.
        ItemService::transition($soft->fresh() ?? $soft, 'cancel', $attorney, 'No longer needed');
        $cancelNotices = ReminderDelivery::query()->where('item_id', $soft->getKey())
            ->where('kind', 'notice')->where('payload->notice_type', 'canceled')->get();
        $this->assertCount(1, $cancelNotices);
        $this->assertSame((string) $paralegal->getKey(), (string) $cancelNotices->first()?->recipient_user_id);
        $this->assertSame('sent', $cancelNotices->first()?->status);
    }

    public function test_reminder_preferences_escalation_and_redaction(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $admin = $this->loader->user('user-admin');
        $oc = $this->loader->user('user-oc');
        $soft = $this->loader->calendarItem('item-soft-deadline');
        $taskA = $this->loader->calendarItem('item-task-a');
        $taskB = $this->loader->calendarItem('item-task-b');
        $hard = $this->loader->calendarItem('item-hard-deadline');
        $internal = $this->loader->calendarItem('item-internal-note');

        // pref-quiet parameters for this scenario: quiet 21:00–07:00
        // America/New_York, digest_time 07:30 (digest starts off for
        // the quiet-hours leg and is enabled for the digest leg).
        ReminderPreference::query()->where('user_id', $paralegal->getKey())->update([
            'quiet_start' => '21:00',
            'quiet_end' => '07:00',
            'timezone' => 'America/New_York',
            'digest_enabled' => false,
            'digest_time' => '07:30',
        ]);

        $forceDue = function ($item): void {
            ReminderDelivery::query()->where('item_id', $item->getKey())
                ->where('status', 'pending')
                ->update(['scheduled_for' => now()->subMinute()]);
        };
        $pendingFor = fn ($item) => ReminderDelivery::query()
            ->where('item_id', $item->getKey())->where('status', 'pending')
            ->orderBy('scheduled_for')->firstOrFail();

        // ── (1) Quiet hours ──────────────────────────────────────
        Carbon::setTestNow(Carbon::parse('2026-10-20 22:30:00', 'America/New_York'));

        ReminderPlanner::setPolicy($soft, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'standard', 'label' => 'Quiet standard',
        ]], $attorney);
        $forceDue($soft);
        $standardId = (string) $pendingFor($soft)->getKey();

        ReminderPlanner::setPolicy($taskA, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'mandatory', 'label' => 'Quiet mandatory',
        ]], $attorney);
        $forceDue($taskA);
        $mandatoryId = (string) $pendingFor($taskA)->getKey();

        Artisan::call('reminders:dispatch');

        $standard = ReminderDelivery::findOrFail($standardId);
        $this->assertSame('pending', $standard->status, 'standard reminder is deferred by quiet hours');
        $this->assertSame(
            Carbon::parse('2026-10-21 07:00:00', 'America/New_York')->setTimezone('UTC')->toIso8601String(),
            $standard->scheduled_for?->toIso8601String(),
            'deferred to the quiet-window end'
        );
        $this->assertSame('sent', ReminderDelivery::findOrFail($mandatoryId)->status, 'mandatory reminder bypasses quiet hours');

        // ── (2) Digest ───────────────────────────────────────────
        ReminderPreference::query()->where('user_id', $paralegal->getKey())->update(['digest_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'America/New_York'));

        ReminderPlanner::setPolicy($soft->fresh() ?? $soft, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'standard', 'label' => 'Digest one',
        ]], $attorney);
        $forceDue($soft);
        $digestOneId = (string) $pendingFor($soft)->getKey();

        ReminderPlanner::setPolicy($taskB, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['user:'.(string) $paralegal->getKey()],
            'criticality' => 'standard', 'label' => 'Digest two',
        ]], $attorney);
        $forceDue($taskB);
        $digestTwoId = (string) $pendingFor($taskB)->getKey();

        ReminderPlanner::setPolicy($taskA->fresh() ?? $taskA, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'mandatory', 'label' => 'Digest mandatory',
        ]], $attorney);
        $forceDue($taskA);
        $digestMandatoryId = (string) $pendingFor($taskA)->getKey();

        $notesBefore = $paralegal->fresh()->notifications()->where('type', ReminderNotification::class)->count();
        Artisan::call('reminders:dispatch');

        foreach ([$digestOneId, $digestTwoId] as $heldId) {
            $held = ReminderDelivery::findOrFail($heldId);
            $this->assertSame('superseded', $held->status, 'standard reminder held for digest');
            $this->assertSame('pending digest', $held->payload['digested_into'] ?? null);
        }
        $this->assertSame('sent', ReminderDelivery::findOrFail($digestMandatoryId)->status, 'mandatory reminder bypasses digest');

        $digests = ReminderDelivery::query()->where('recipient_user_id', $paralegal->getKey())
            ->where('kind', 'digest')->where('status', 'pending')->get();
        $this->assertCount(1, $digests, 'exactly one pending digest delivery');
        $digest = $digests->first();
        $this->assertNotNull($digest);
        $this->assertSame(
            Carbon::parse('2026-10-22 07:30:00', 'America/New_York')->setTimezone('UTC')->toIso8601String(),
            $digest->scheduled_for?->toIso8601String()
        );
        $digestTitles = collect($digest->payload['digest_items'] ?? [])->pluck('item_title')->all();
        $this->assertEqualsCanonicalizing([$soft->title, $taskB->title], $digestTitles);

        // Only the mandatory send produced a notification so far.
        $this->assertSame($notesBefore + 1, $paralegal->fresh()->notifications()->where('type', ReminderNotification::class)->count());

        Carbon::setTestNow(Carbon::parse('2026-10-22 07:30:00', 'America/New_York'));
        Artisan::call('reminders:dispatch');

        $this->assertSame('sent', ReminderDelivery::findOrFail($digest->getKey())->status);
        $digestNotes = $paralegal->fresh()->notifications()->where('type', ReminderNotification::class)->get()
            ->filter(fn ($n) => ($n->data['delivery_id'] ?? null) === (string) $digest->getKey());
        $this->assertCount(1, $digestNotes, 'exactly one in_app notification for the digest');
        $this->assertEqualsCanonicalizing([$soft->title, $taskB->title], $digestNotes->first()->data['item_titles'] ?? []);

        // ── (3) Escalation (and ack prevention) ──────────────────
        // A mandatory reminder for the soft item, sent then ACKED:
        // it must never escalate.
        ReminderPlanner::setPolicy($soft->fresh() ?? $soft, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'mandatory', 'label' => 'Acked mandatory',
        ]], $attorney);
        $forceDue($soft);
        $ackedId = (string) $pendingFor($soft)->getKey();
        Artisan::call('reminders:dispatch');
        $this->assertSame('sent', ReminderDelivery::findOrFail($ackedId)->status);

        $this->actingAs($paralegal)->postJson(route('notifications.acknowledge', $ackedId))->assertOk();
        $acked = ReminderDelivery::findOrFail($ackedId);
        $this->assertSame('acknowledged', $acked->status);
        $this->assertNotNull($acked->acknowledged_at);
        $this->assertSame((string) $paralegal->getKey(), (string) $acked->acknowledged_by);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.reminder.acknowledged')
            ->where('payload->delivery_id', $ackedId)->exists());

        // Hard deadline: a mandatory delivery sent and unacknowledged.
        $hardSent = ReminderDelivery::query()->where('item_id', $hard->getKey())
            ->where('channel', 'in_app')->where('status', 'pending')->firstOrFail();
        $hardSent->forceFill(['status' => 'sent', 'sent_at' => now(), 'attempts' => 1])->save();

        $hardBase = ReminderPlanner::baseInstant($hard->fresh() ?? $hard);
        $this->assertNotNull($hardBase);
        Carbon::setTestNow($hardBase->copy()->addHour());

        Artisan::call('reminders:escalate');

        $escalations = ReminderDelivery::query()->where('item_id', $hard->getKey())->where('kind', 'escalation')->get();
        $this->assertCount(1, $escalations);
        $escalation = $escalations->first();
        $this->assertNotNull($escalation);
        $this->assertSame((string) $admin->getKey(), (string) $escalation->recipient_user_id, 'escalation goes to the escalation owner');
        $this->assertSame('mandatory', $escalation->payload['criticality'] ?? null);
        $this->assertSame($hard->title, $escalation->payload['item_title'] ?? null, 'escalation carries item context');
        $this->assertSame('sent', $escalation->status);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.reminder.escalated')
            ->where('payload->item_id', (string) $hard->getKey())->exists());
        $this->assertSame(0, ReminderDelivery::query()->where('item_id', $soft->getKey())->where('kind', 'escalation')->count(), 'acknowledged mandatory reminder never escalates');

        Artisan::call('reminders:escalate');
        $this->assertSame(1, ReminderDelivery::query()->where('item_id', $hard->getKey())->where('kind', 'escalation')->count(), 'escalation sweep is idempotent per item');

        ReminderPreference::query()->where('user_id', $paralegal->getKey())->update(['digest_enabled' => false]);

        // ── (4) Snooze ───────────────────────────────────────────
        $baseSoft = ReminderPlanner::baseInstant($soft->fresh() ?? $soft);
        $baseTaskB = ReminderPlanner::baseInstant($taskB->fresh() ?? $taskB);
        $baseHard = ReminderPlanner::baseInstant($hard->fresh() ?? $hard);
        $this->assertNotNull($baseSoft);
        $this->assertNotNull($baseTaskB);
        $this->assertNotNull($baseHard);
        Carbon::setTestNow($baseSoft->copy()->subDay()->setTimezone('America/New_York')->setTime(12, 0));

        // (a) beyond due, recipient without matter edit (oc) -> 422
        ReminderPlanner::setPolicy($taskB->fresh() ?? $taskB, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['user:'.(string) $oc->getKey()],
            'criticality' => 'standard', 'label' => 'Snooze oc',
        ]], $attorney);
        $forceDue($taskB);
        $snoozeAId = (string) $pendingFor($taskB)->getKey();

        // (b) beyond due, recipient with matter edit (attorney) -> ok
        ReminderPlanner::setPolicy($hard->fresh() ?? $hard, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'mandatory', 'label' => 'Snooze attorney',
        ]], $attorney);
        $forceDue($hard);
        $snoozeBId = (string) $pendingFor($hard)->getKey();

        // (c) within due, recipient (oc) -> ok
        ReminderPlanner::setPolicy($soft->fresh() ?? $soft, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['user:'.(string) $oc->getKey()],
            'criticality' => 'standard', 'label' => 'Snooze within',
        ]], $attorney);
        $forceDue($soft);
        $snoozeCId = (string) $pendingFor($soft)->getKey();

        Artisan::call('reminders:dispatch');
        foreach ([$snoozeAId, $snoozeBId, $snoozeCId] as $sentId) {
            $this->assertSame('sent', ReminderDelivery::findOrFail($sentId)->status);
        }

        $this->actingAs($oc)->postJson(route('notifications.snooze', $snoozeAId), [
            'until' => $baseTaskB->copy()->addDay()->toIso8601String(),
        ])->assertStatus(422);
        $this->assertSame('sent', ReminderDelivery::findOrFail($snoozeAId)->status);

        $this->actingAs($attorney)->postJson(route('notifications.snooze', $snoozeBId), [
            'until' => $baseHard->copy()->addDay()->toIso8601String(),
        ])->assertOk();
        $snoozedB = ReminderDelivery::findOrFail($snoozeBId);
        $this->assertSame('snoozed', $snoozedB->status);
        $this->assertNotNull($snoozedB->snoozed_until);

        $this->actingAs($oc)->postJson(route('notifications.snooze', $snoozeCId), [
            'until' => $baseSoft->copy()->subHour()->toIso8601String(),
        ])->assertOk();
        $this->assertSame('snoozed', ReminderDelivery::findOrFail($snoozeCId)->status);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.reminder.snoozed')
            ->where('payload->delivery_id', $snoozeCId)->exists());

        // Non-recipient gets no existence signal.
        $this->actingAs($this->loader->user('user-viewer'))->postJson(route('notifications.acknowledge', $snoozeAId))->assertStatus(404);

        // Snooze expiry: the snoozed delivery is due again and a
        // re-send lands it back at sent.
        Carbon::setTestNow($baseSoft->copy()->addMinutes(5));
        Artisan::call('reminders:dispatch');
        $this->assertSame('sent', ReminderDelivery::findOrFail($snoozeCId)->status, 'expired snooze re-sends to sent');

        // ── (5) Redaction ────────────────────────────────────────
        config(['calendaring.email_enabled' => true]);
        app()->instance('calendaring.channel.email', new EmailChannel);
        Mail::fake();

        ReminderPlanner::setPolicy($internal, [[
            'offset_minutes' => 0, 'channels' => ['in_app', 'email'], 'recipients' => ['assignee'],
            'criticality' => 'standard', 'label' => 'Restricted notice',
        ]], $attorney);
        $forceDue($internal);
        Artisan::call('reminders:dispatch');

        $emailRow = ReminderDelivery::query()->where('item_id', $internal->getKey())->where('channel', 'email')->firstOrFail();
        $inAppRow = ReminderDelivery::query()->where('item_id', $internal->getKey())->where('channel', 'in_app')->firstOrFail();
        $this->assertSame('sent', $emailRow->status);
        $this->assertSame('sent', $inAppRow->status);
        Mail::assertSent(ReminderMail::class);

        $restrictedMatter = $this->loader->matter('matter-restricted');
        $mail = new ReminderMail($emailRow->fresh() ?? $emailRow);
        $subject = $mail->envelope()->subject;
        $html = $mail->render();
        foreach ([$subject, $html] as $surface) {
            $this->assertStringNotContainsString($restrictedMatter->title, (string) $surface, 'email must not carry the matter title');
            $this->assertStringNotContainsString($internal->title, (string) $surface, 'email must not carry the item title');
        }

        // The in-app notification for the same item DOES carry it.
        $note = $attorney->fresh()->notifications()->where('type', ReminderNotification::class)->get()
            ->first(fn ($n) => ($n->data['delivery_id'] ?? null) === (string) $inAppRow->getKey());
        $this->assertNotNull($note);
        $this->assertSame($internal->title, $note->data['item_title'] ?? null);

        Carbon::setTestNow();
    }

    public function test_reminder_pipeline_is_idempotent_under_failure(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $admin = $this->loader->user('user-admin');
        $soft = $this->loader->calendarItem('item-soft-deadline');
        $taskA = $this->loader->calendarItem('item-task-a');
        $taskB = $this->loader->calendarItem('item-task-b');

        // Deterministic preferences: no quiet window, no digest.
        ReminderPreference::query()->where('user_id', $paralegal->getKey())->update([
            'quiet_start' => null, 'quiet_end' => null, 'digest_enabled' => false,
        ]);

        // A channel double keyed by the delivery idempotency key:
        // it records at most ONE provider send per key and returns
        // success for an already-seen key without sending again.
        $makeDouble = fn () => new class implements ReminderChannel
        {
            /** @var array<string, bool> */
            public array $sentKeys = [];

            public int $providerSends = 0;

            public string $behavior = 'succeed';

            public function send(ReminderDelivery $delivery): string
            {
                $key = $delivery->idempotency_key;
                if (isset($this->sentKeys[$key])) {
                    return 'provider-'.$key;
                }
                if ($this->behavior === 'fail') {
                    throw new \RuntimeException('Provider down');
                }
                $this->sentKeys[$key] = true;
                $this->providerSends++;
                if ($this->behavior === 'timeout') {
                    throw new \RuntimeException('Provider timeout after send');
                }

                return 'provider-'.$key;
            }
        };

        $forceDue = function ($item): string {
            ReminderDelivery::query()->where('item_id', $item->getKey())
                ->where('status', 'pending')
                ->update(['scheduled_for' => now()->subMinute()]);
            $row = ReminderDelivery::query()->where('item_id', $item->getKey())
                ->where('status', 'pending')->firstOrFail();

            return (string) $row->getKey();
        };

        // ── (1) Timeout after send ───────────────────────────────
        ReminderPlanner::setPolicy($soft, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'standard', 'label' => 'Timeout leg',
        ]], $attorney);
        $timeoutId = $forceDue($soft);

        $double = $makeDouble();
        $double->behavior = 'timeout';
        app()->instance('calendaring.channel.in_app', $double);

        Artisan::call('reminders:dispatch');
        $row = ReminderDelivery::findOrFail($timeoutId);
        $this->assertSame(1, $double->providerSends, 'provider recorded the send before timing out');
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);

        // Retry (backoff bypassed by making the retry due): the
        // double returns success for the seen key WITHOUT a second
        // provider send.
        ReminderDelivery::query()->whereKey($timeoutId)->update(['next_attempt_at' => now()->subMinute()]);
        Artisan::call('reminders:dispatch');

        $row = ReminderDelivery::findOrFail($timeoutId);
        $this->assertSame(1, $double->providerSends, 'exactly one provider send for the key');
        $this->assertSame('sent', $row->status);

        // ── (2) Worker restart: stale claim is reclaimed ─────────
        ReminderPlanner::setPolicy($taskA, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['assignee'],
            'criticality' => 'standard', 'label' => 'Restart leg',
        ]], $attorney);
        $restartId = $forceDue($taskA);

        $double2 = $makeDouble();
        app()->instance('calendaring.channel.in_app', $double2);

        // Leave the row claimed by a dead worker 15 minutes ago.
        ReminderDelivery::query()->whereKey($restartId)->update([
            'status' => 'claimed',
            'updated_at' => now()->subMinutes(15),
        ]);

        Artisan::call('reminders:dispatch');

        $row = ReminderDelivery::findOrFail($restartId);
        $this->assertSame('sent', $row->status, 'stale claim reclaimed and delivered');
        $this->assertSame(1, $double2->providerSends, 'still exactly one provider send for its key');

        // ── (3) Dead letter + admin replay ───────────────────────
        ReminderPlanner::setPolicy($taskB, [[
            'offset_minutes' => 0, 'channels' => ['in_app'], 'recipients' => ['user:'.(string) $paralegal->getKey()],
            'criticality' => 'standard', 'label' => 'Dead leg',
        ]], $attorney);
        $deadId = $forceDue($taskB);

        $double3 = $makeDouble();
        $double3->behavior = 'fail';
        app()->instance('calendaring.channel.in_app', $double3);

        $service = app(DeliveryService::class);
        for ($i = 0; $i < DeliveryService::MAX_ATTEMPTS; $i++) {
            $service->deliver(ReminderDelivery::findOrFail($deadId));
        }
        $row = ReminderDelivery::findOrFail($deadId);
        $this->assertSame('dead', $row->status, 'row is dead after the attempt budget');
        $this->assertSame(DeliveryService::MAX_ATTEMPTS, (int) $row->attempts);
        $this->assertSame(0, $double3->providerSends);

        // Ops view: org admin sees the dead row filtered by status;
        // a non-admin is refused.
        $this->actingAs($attorney)->get(route('admin.reminders.index'))->assertStatus(403);
        $opsJson = $this->actingAs($admin)->getJson(route('admin.reminders.index', ['status' => 'dead']));
        $opsJson->assertOk();
        $deadListed = collect($opsJson->json('deliveries'))->firstWhere('id', $deadId);
        $this->assertNotNull($deadListed, 'ops view lists the dead delivery');
        $this->assertSame($taskB->title, $deadListed['item_title'] ?? null);
        $this->assertSame('in_app', $deadListed['channel'] ?? null);
        $this->assertSame(DeliveryService::MAX_ATTEMPTS, $deadListed['attempts'] ?? null);
        $this->assertNotNull($deadListed['last_error'] ?? null);
        $this->actingAs($admin)->get(route('admin.reminders.index', ['status' => 'dead']))
            ->assertOk()->assertSee($taskB->title)->assertSee('dead');

        // Replay via the ops route: pending again, attempts kept.
        $this->actingAs($admin)->postJson(route('admin.reminders.replay', $deadId))->assertOk();
        $row = ReminderDelivery::findOrFail($deadId);
        $this->assertSame('pending', $row->status);
        $this->assertSame(DeliveryService::MAX_ATTEMPTS, (int) $row->attempts, 'attempts preserved for the record');
        $this->assertNull($row->next_attempt_at);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.reminder.replayed')
            ->where('payload->delivery_id', $deadId)->exists());

        // A non-replayable row is refused.
        $this->actingAs($admin)->postJson(route('admin.reminders.replay', $deadId))->assertStatus(422);

        $double3->behavior = 'succeed';
        Artisan::call('reminders:dispatch');
        $row = ReminderDelivery::findOrFail($deadId);
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, $double3->providerSends, 'exactly one provider send after replay');

        // ── (4) Duplicate dispatch ───────────────────────────────
        Artisan::call('reminders:dispatch');
        $deliveriesAfterFirst = ReminderDelivery::count();
        $notesAfterFirst = $paralegal->fresh()->notifications()->count();
        $sendsAfterFirst = $double3->providerSends;

        Artisan::call('reminders:dispatch');
        $this->assertSame($deliveriesAfterFirst, ReminderDelivery::count(), 'no duplicate deliveries');
        $this->assertSame($notesAfterFirst, $paralegal->fresh()->notifications()->count(), 'no duplicate notifications');
        $this->assertSame($sendsAfterFirst, $double3->providerSends, 'no duplicate provider sends');
    }
}
