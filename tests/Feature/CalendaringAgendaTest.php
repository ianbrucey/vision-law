<?php

namespace Tests\Feature;

use App\Models\CalendarItem;
use App\Models\ReminderDelivery;
use App\Services\Calendaring\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringAgendaTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_unified_agenda_combines_items_across_authorized_matters_only(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $restrictedItem = $this->loader->calendarItem('item-internal-note');
        $rivalMatter = $this->loader->matter('matter-rival');

        // A rival item exists in the other org.
        CalendarItem::create([
            'org_id' => (string) $rivalMatter->org_id,
            'matter_id' => (string) $rivalMatter->getKey(),
            'type' => 'deadline',
            'title' => 'Rival secret deadline',
            'status' => 'confirmed',
            'due_date' => now()->addDays(2)->toDateString(),
            'timezone' => 'UTC',
        ]);

        // Change a matter-1 task's status so the changed state is auditable.
        $taskA = $this->loader->calendarItem('item-task-a');
        ItemService::transition($taskA, 'complete', $attorney);

        $today = now()->toDateString();
        $monthAnchor = now()->startOfMonth()->toDateString();

        // LIST: exactly the authorized set (matter-1 + matter-2), nothing else.
        $list = $this->actingAs($attorney)->getJson(route('calendar.agenda', ['mode' => 'list', 'anchor' => $today]));
        $list->assertOk();
        $titles = collect($list->json('items'))->pluck('title')->all();
        $this->assertContains('Summary judgment response due', $titles);
        $this->assertContains('Draft expert report (internal target)', $titles);
        $this->assertContains('Unassigned: schedule lease inspection', $titles);
        $this->assertContains($restrictedItem->title, $titles, 'Attorney is owner of the restricted matter, so its item is authorized for them');
        $this->assertNotContains('Rival secret deadline', $titles);
        $this->assertSame(
            CalendarItem::query()->whereIn('matter_id', [
                (string) $this->loader->matter('matter-1')->getKey(),
                (string) $this->loader->matter('matter-2')->getKey(),
                (string) $this->loader->matter('matter-restricted')->getKey(),
            ])->count(),
            $list->json('counts.total'),
            'Attorney is owner of matter-1, matter-2 and the restricted matter, so list counts all three'
        );

        // Paralegal: grants on matter-1 + matter-2, NO grant on restricted.
        $para = $this->actingAs($paralegal)->getJson(route('calendar.agenda', ['mode' => 'list', 'anchor' => $today]));
        $para->assertOk();
        $paraTitles = collect($para->json('items'))->pluck('title')->all();
        $this->assertContains('Summary judgment response due', $paraTitles);
        $this->assertContains('Unassigned: schedule lease inspection', $paraTitles);
        $this->assertNotContains($restrictedItem->title, $paraTitles);
        $this->assertNotContains('Rival secret deadline', $paraTitles);

        // DAY / WEEK / MONTH modes: no leaks in any mode.
        foreach (['day', 'week', 'month'] as $mode) {
            $resp = $this->actingAs($paralegal)->getJson(route('calendar.agenda', ['mode' => $mode, 'anchor' => $today]));
            $resp->assertOk();
            $modeTitles = collect($resp->json('items'))->pluck('title')->all();
            $this->assertNotContains($restrictedItem->title, $modeTitles, "restricted leaked in {$mode}");
            $this->assertNotContains('Rival secret deadline', $modeTitles, "rival leaked in {$mode}");
        }

        // Month agenda (HTML) for attorney: the changed item renders with
        // before/after status reachable.
        Carbon::setTestNow(now());
        $html = $this->actingAs($attorney)->get(route('calendar.index', ['mode' => 'month', 'anchor' => $monthAnchor]));
        $html->assertOk();
        $html->assertSee('Collect Apex contract documents');
        $html->assertSee('Changed:');
        $html->assertSee('confirmed');
        $html->assertSee('completed');
        $html->assertDontSee('Rival secret deadline');

        // JSON carries the changed annotation too (month window contains
        // both the item's due date and today's transition).
        $month = $this->actingAs($attorney)->getJson(route('calendar.agenda', ['mode' => 'month', 'anchor' => $monthAnchor]));
        $month->assertOk();
        $changed = collect($month->json('items'))->firstWhere('id', (string) $taskA->getKey());
        $this->assertNotNull($changed, 'task-a must be in the month agenda');
        $this->assertTrue($changed['changed']);
        $this->assertSame('confirmed', $changed['changed_from']);
        $this->assertSame('completed', $changed['changed_to']);

        // Matter calendar page renders for an authorized user.
        $this->actingAs($attorney)->get(route('matters.calendar', $this->loader->matter('matter-1')))
            ->assertOk()->assertSee('Summary judgment response due');

        // Filter persistence (009-D26): save a type filter, then a bare
        // agenda read applies it.
        $this->actingAs($attorney)->postJson(route('calendar.filters'), ['types' => ['task']])->assertOk();
        $saved = $this->actingAs($attorney)->getJson(route('calendar.agenda'));
        $saved->assertOk();
        foreach ($saved->json('items') as $row) {
            $this->assertSame('task', $row['type']);
        }
        $this->assertNotEmpty($saved->json('items'));
    }

    /**
     * T-17 (009-D25): the home deadlines module shows only the
     * caller's authorized upcoming confirmed items (overdue first),
     * with scoped counts, and is hidden entirely for a user with no
     * accessible matters (spec 010 C-04 behavior preserved).
     */
    public function test_home_deadlines_module_shows_only_authorized_upcoming_items(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $admin = $this->loader->user('user-admin');
        $viewer = $this->loader->user('user-viewer');
        $matter1 = $this->loader->matter('matter-1');
        $restrictedItem = $this->loader->calendarItem('item-internal-note');

        $overdue = ItemService::create($matter1, [
            'type' => 'deadline', 'title' => 'Overdue audit filing',
            'due_date' => now()->subDay()->toDateString(),
            'timezone' => 'America/New_York', 'status' => 'confirmed',
        ], $attorney);

        // One unacknowledged mandatory reminder for the attorney.
        $hard = $this->loader->calendarItem('item-hard-deadline');
        ReminderDelivery::create([
            'org_id' => (string) $hard->org_id,
            'matter_id' => (string) $hard->matter_id,
            'item_id' => (string) $hard->getKey(),
            'kind' => 'reminder',
            'recipient_user_id' => (string) $attorney->getKey(),
            'recipient_email' => $attorney->email,
            'channel' => 'in_app',
            'idempotency_key' => (string) Str::uuid(),
            'scheduled_for' => now()->subHour(),
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
            'payload' => ['item_title' => $hard->title, 'criticality' => 'mandatory'],
        ]);

        $home = $this->actingAs($attorney)->get(route('app.home'));
        $home->assertOk();
        $home->assertSee('Deadlines & agenda');
        $home->assertSee('Overdue audit filing');
        // Overdue first, then nearest upcoming (restricted item is the
        // attorney's own matter, +3d, so it is authorized here).
        $home->assertSeeInOrder(['Overdue audit filing', $restrictedItem->title]);
        $home->assertSee('Overdue', false);
        $content = (string) $home->getContent();
        $this->assertMatchesRegularExpression('/>1<\/div>\s*<div class="text-\[13px\] text-vl-mut">Overdue<\/div>/', $content, 'overdue count must be 1');
        $this->assertMatchesRegularExpression('/>1<\/div>\s*<div class="text-\[13px\] text-vl-mut">Unacknowledged mandatory reminders<\/div>/', $content, 'unacked mandatory count must be 1');
        // Draft items never appear in the module.
        $home->assertDontSee('Unconfirmed draft deadline');

        // Grantless org admin: module present (they have accessible
        // matters) but the restricted item and its counts stay out.
        $adminHome = $this->actingAs($admin)->get(route('app.home'));
        $adminHome->assertOk();
        $adminHome->assertSee('Deadlines & agenda');
        $adminHome->assertDontSee($restrictedItem->title);
        $adminHome->assertSee('Overdue audit filing');

        // Zero accessible matters → no module at all (010 preserved).
        $viewerHome = $this->actingAs($viewer)->get(route('app.home'));
        $viewerHome->assertOk();
        $viewerHome->assertDontSee('Deadlines & agenda');
        $viewerHome->assertDontSee('Overdue audit filing');
        $viewerHome->assertSee('No matters are shared with you yet');
    }
}
