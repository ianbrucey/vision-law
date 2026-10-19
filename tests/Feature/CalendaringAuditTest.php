<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\Calendaring\InstantiationService;
use App\Services\Calendaring\ItemService;
use App\Services\Calendaring\RecalculationService;
use App\Services\Calendaring\TaskService;
use App\Services\Calendaring\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-19 (009 T-16, 009-D24): the calendaring audit trail is complete
 * (every material action in the D24 set, exercised end-to-end) and
 * append-only (model guard + 001 DB trigger). Payloads must be
 * blocklist-safe: no key containing token/secret/hash/password/
 * recovery at any depth (feeds/shares are referenced by id only).
 */
class CalendaringAuditTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
        Artisan::call('calendaring:load-holiday-packs');
    }

    public function test_calendaring_audit_is_append_only_and_complete(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $admin = $this->loader->user('user-admin');
        $paralegal = $this->loader->user('user-paralegal');
        $matter1 = $this->loader->matter('matter-1');

        // ── Item lifecycle: create → confirm → complete, plus a ─────
        // cancel and a supersede chain on sibling items.
        $item = ItemService::create($matter1, [
            'type' => 'deadline', 'title' => 'Audit trail deadline',
            'due_date' => now()->addDays(9)->toDateString(),
            'timezone' => 'America/New_York', 'status' => 'draft',
        ], $attorney);
        ItemService::transition($item, 'confirm', $attorney);
        ItemService::transition($item->fresh(), 'complete', $attorney);

        $cancelMe = ItemService::create($matter1, [
            'type' => 'event', 'title' => 'Audit trail event',
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'timezone' => 'UTC', 'status' => 'confirmed',
        ], $attorney);
        ItemService::transition($cancelMe, 'cancel', $attorney, 'No longer needed');

        $original = ItemService::create($matter1, [
            'type' => 'deadline', 'title' => 'Audit trail original',
            'due_date' => now()->addDays(20)->toDateString(),
            'timezone' => 'America/New_York', 'status' => 'confirmed',
        ], $attorney);
        ItemService::supersede($original->fresh(), ['due_date' => now()->addDays(21)->toDateString()], $attorney, 'Court moved the date');

        // Reopen leg: complete → reopen emits calendar.item.reopened.
        $reopenMe = ItemService::create($matter1, [
            'type' => 'deadline', 'title' => 'Audit trail reopen',
            'due_date' => now()->addDays(4)->toDateString(),
            'timezone' => 'UTC', 'status' => 'confirmed',
        ], $attorney);
        ItemService::transition($reopenMe, 'complete', $attorney);
        ItemService::transition($reopenMe->fresh(), 'reopen', $attorney);

        // ── Tasks: assign, checklist override + complete, reopen ────
        $taskB = $this->loader->calendarItem('item-task-b');
        TaskService::assign($taskB, (string) $paralegal->getKey(), $attorney);
        TaskService::complete($taskB->fresh(), $attorney, 'Filing deadline forces completion');
        TaskService::transitionStatus($taskB->fresh(), 'in_progress', $attorney);

        // ── Templates: create → version → submit → publish → retire ─
        $template = TemplateService::createTemplate($attorney, [
            'name' => 'Audit Trail Template (DEMONSTRATION)',
            'jurisdiction_label' => 'Georgia',
        ]);
        $fixtureVersion = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $this->loader->deadlineTemplate('tpl-demo-litigation')->getKey())
            ->where('version', 1)->firstOrFail();
        $version = TemplateService::createVersion($attorney, $template, ['rules' => $fixtureVersion->rules]);
        TemplateService::submit($attorney, $version);
        TemplateService::publish($admin, $version->fresh());
        TemplateService::retire($admin, $version->fresh());

        // ── Instantiation: preview (HTTP, audited) → confirm ─────────
        $published = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $this->loader->deadlineTemplate('tpl-demo-litigation')->getKey())
            ->where('version', 1)->firstOrFail();
        $inputs = [
            'trigger_date' => '2026-10-30',
            'service_date' => '2026-10-30',
            'timezone' => 'America/New_York',
        ];
        $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.preview', $matter1),
            ['template_version_id' => (string) $published->getKey(), 'inputs' => $inputs]
        )->assertOk();
        $batch = InstantiationService::confirm($matter1, $published, $inputs, 'c19-batch-1', $attorney);

        // ── Recalculation: propose (HTTP) → apply ────────────────────
        $this->actingAs($attorney)->postJson(
            route('calendar.instantiations.recalculate.preview', [$matter1, $batch]),
            ['trigger_date' => '2026-11-06']
        )->assertOk();
        RecalculationService::apply($batch, array_merge($batch->inputs ?? [], ['trigger_date' => '2026-11-06']), $attorney);

        // ── Reminders: ack, snooze, escalate, replay ─────────────────
        $hard = $this->loader->calendarItem('item-hard-deadline');
        $ackDelivery = $this->sentDelivery($hard, $attorney);
        $this->actingAs($attorney)->postJson(route('notifications.acknowledge', $ackDelivery))->assertOk();

        $snoozeDelivery = $this->sentDelivery($hard, $attorney);
        $this->actingAs($attorney)->postJson(route('notifications.snooze', $snoozeDelivery), [
            'until' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        $escalationSource = $this->sentDelivery($hard, $attorney);
        Carbon::setTestNow(now()->addDays(30));
        try {
            Artisan::call('reminders:escalate');
        } finally {
            Carbon::setTestNow();
        }
        $this->assertNotNull($escalationSource);

        $dead = ReminderDelivery::create([
            'org_id' => (string) $hard->org_id,
            'matter_id' => (string) $hard->matter_id,
            'item_id' => (string) $hard->getKey(),
            'kind' => 'reminder',
            'recipient_user_id' => (string) $attorney->getKey(),
            'recipient_email' => $attorney->email,
            'channel' => 'in_app',
            'idempotency_key' => (string) Str::uuid(),
            'scheduled_for' => now(),
            'status' => 'dead',
            'attempts' => 5,
            'payload' => ['item_title' => $hard->title, 'criticality' => 'mandatory'],
        ]);
        $this->actingAs($admin)->postJson(route('admin.reminders.replay', $dead))->assertOk();

        // ── External: export, feed create/revoke, share create/revoke ─
        $this->actingAs($attorney)->get(route('calendar.export'))->assertOk();
        $feed = $this->actingAs($attorney)->postJson(route('calendar.feeds.store'));
        $feed->assertCreated();
        $this->get('/feeds/calendar/'.$feed->json('token').'.ics')->assertOk();
        $this->actingAs($attorney)->deleteJson(route('calendar.feeds.destroy', $feed->json('id')))->assertOk();

        $share = $this->actingAs($attorney)->postJson(route('calendar.shares.store', $hard), [
            'email' => 'r.rivera@outside.example',
            'fields' => ['title', 'date'],
        ]);
        $share->assertCreated();
        $this->actingAs($attorney)->deleteJson(route('calendar.shares.revoke', $share->json('id')))->assertOk();

        // ── Restricted toggle (009-D04) ────────────────────────────────
        $this->actingAs($attorney)->postJson(route('matters.restricted', $matter1), ['restricted' => true])
            ->assertOk()->assertJsonPath('restricted', true);
        $this->actingAs($attorney)->postJson(route('matters.restricted', $matter1), ['restricted' => false])
            ->assertOk()->assertJsonPath('restricted', false);

        // ── The D24 set is complete ────────────────────────────────────
        $expected = [
            'calendar.item.created',
            'calendar.item.confirmed',
            'calendar.item.completed',
            'calendar.item.canceled',
            'calendar.item.reopened',
            'calendar.item.superseded',
            'calendar.task.assigned',
            'calendar.task.completed',
            'calendar.task.reopened',
            'calendar.task.checklist_overridden',
            'calendar.template.created',
            'calendar.template.submitted',
            'calendar.template.published',
            'calendar.template.retired',
            'calendar.instantiation.previewed',
            'calendar.instantiation.confirmed',
            'calendar.recalculation.proposed',
            'calendar.recalculation.applied',
            'calendar.reminder.acknowledged',
            'calendar.reminder.snoozed',
            'calendar.reminder.escalated',
            'calendar.reminder.replayed',
            'calendar.export.performed',
            'calendar.feed.created',
            'calendar.feed.issued',
            'calendar.feed.accessed',
            'calendar.feed.revoked',
            'calendar.share.created',
            'calendar.share.revoked',
            'calendar.guest.invited',
            'calendar.guest.revoked',
            'matter.restricted.changed',
        ];

        foreach ($expected as $event) {
            $this->assertTrue(
                AuditEvent::query()->where('event', $event)->exists(),
                "missing audit event {$event}"
            );
        }

        // Dependency change is exercised by the fixture task chain's
        // service path — assert its event from a direct add/remove.
        $taskA = $this->loader->calendarItem('item-task-a');
        $freshTask = ItemService::create($matter1, [
            'type' => 'task', 'title' => 'Audit dependency successor',
            'task_status' => 'not_started',
            'due_date' => now()->addDays(12)->toDateString(),
            'timezone' => 'UTC', 'status' => 'confirmed',
        ], $attorney);
        TaskService::addDependency($taskA, $freshTask, $attorney);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.task.dependency_changed')->exists());

        // ── Payloads are blocklist-safe (no privileged key names) ────
        $blocked = ['token', 'secret', 'hash', 'password', 'recovery'];
        foreach (AuditEvent::query()->where('event', 'like', 'calendar.%')->get() as $row) {
            $this->assertPayloadKeysSafe($row->payload ?? [], $blocked, (string) $row->event);
        }

        // ── Append-only: model guard AND the 001 DB trigger ──────────
        $row = AuditEvent::query()->where('event', 'calendar.item.created')->firstOrFail();

        $modelThrew = false;
        try {
            $row->event = 'tampered';
            $row->save();
        } catch (\Throwable) {
            $modelThrew = true;
        }
        $this->assertTrue($modelThrew, 'model-level update of an audit row must throw');

        // Nested transactions = SAVEPOINTs, so the trigger's raised
        // exception rolls back only the probe, not the test's outer
        // transaction (Postgres would otherwise abort it: 25P02).
        $updateThrew = false;
        try {
            DB::transaction(fn () => DB::statement('UPDATE audit_events SET event = ? WHERE id = ?', ['tampered', (string) $row->getKey()]));
        } catch (\Throwable) {
            $updateThrew = true;
        }
        $this->assertTrue($updateThrew, 'direct UPDATE on audit_events must throw (001 trigger)');

        $deleteThrew = false;
        try {
            DB::transaction(fn () => DB::statement('DELETE FROM audit_events WHERE id = ?', [(string) $row->getKey()]));
        } catch (\Throwable) {
            $deleteThrew = true;
        }
        $this->assertTrue($deleteThrew, 'direct DELETE on audit_events must throw (001 trigger)');

        $this->assertDatabaseHas('audit_events', ['id' => (string) $row->getKey(), 'event' => 'calendar.item.created']);
    }

    private function sentDelivery(CalendarItem $item, User $recipient): ReminderDelivery
    {
        return ReminderDelivery::create([
            'org_id' => (string) $item->org_id,
            'matter_id' => (string) $item->matter_id,
            'item_id' => (string) $item->getKey(),
            'kind' => 'reminder',
            'recipient_user_id' => (string) $recipient->getKey(),
            'recipient_email' => $recipient->email,
            'channel' => 'in_app',
            'idempotency_key' => (string) Str::uuid(),
            'scheduled_for' => now()->subHour(),
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
            'payload' => ['item_title' => $item->title, 'criticality' => 'mandatory'],
        ]);
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $blocked
     */
    private function assertPayloadKeysSafe(array $payload, array $blocked, string $path): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach ($blocked as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        strtolower($key),
                        "audit payload key {$path}.{$key} contains blocked substring {$needle}"
                    );
                }
            }
            if (is_array($value)) {
                $this->assertPayloadKeysSafe($value, $blocked, $path.'.'.(string) $key);
            }
        }
    }
}
