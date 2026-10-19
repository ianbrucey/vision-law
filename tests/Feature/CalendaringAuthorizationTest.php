<?php

namespace Tests\Feature;

use App\Models\CalendarItem;
use App\Models\MatterGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_calendaring_items_inherit_matter_authorization(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $admin = $this->loader->user('user-admin');
        $rival = $this->loader->user('user-rival');
        $viewer = $this->loader->user('user-viewer');

        $item = $this->loader->calendarItem('item-hard-deadline');
        $restrictedMatter = $this->loader->matter('matter-restricted');
        $restrictedItem = $this->loader->calendarItem('item-internal-note');

        // Attorney (grant / owner on matter-1) can view the item show page.
        $this->actingAs($attorney)
            ->get(route('calendar.items.show', $item))
            ->assertOk()
            ->assertSee($item->title);

        // Attorney is owner of the restricted matter too — can view it.
        $this->actingAs($attorney)
            ->get(route('calendar.items.show', $restrictedItem))
            ->assertOk()
            ->assertSee($restrictedItem->title);

        // Org admin CANNOT see the restricted matter or its item (009-D04).
        $this->actingAs($admin)
            ->get(route('matters.show', $restrictedMatter))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('calendar.items.show', $restrictedItem))
            ->assertNotFound()
            ->assertDontSee($restrictedItem->title)
            ->assertDontSee($restrictedMatter->title);

        // Admin CAN after a grant is added.
        MatterGrant::create([
            'org_id' => (string) $restrictedMatter->org_id,
            'matter_id' => (string) $restrictedMatter->getKey(),
            'user_id' => (string) $admin->getKey(),
            'role' => 'editor',
            'granted_by' => (string) $attorney->getKey(),
        ]);

        $this->actingAs($admin)
            ->get(route('calendar.items.show', $restrictedItem))
            ->assertOk()
            ->assertSee($restrictedItem->title);

        // Org-B rival gets 404 on matter-1 items.
        $this->actingAs($rival)
            ->get(route('calendar.items.show', $item))
            ->assertNotFound()
            ->assertDontSee($item->title);

        // Viewer (no grants) gets 404.
        $this->actingAs($viewer)
            ->get(route('calendar.items.show', $item))
            ->assertNotFound()
            ->assertDontSee($item->title);
    }

    public function test_no_existence_leak_via_search_counts_export_or_feed(): void
    {
        $admin = $this->loader->user('user-admin');
        $rival = $this->loader->user('user-rival');
        $restrictedItem = $this->loader->calendarItem('item-internal-note');
        $restrictedMatter = $this->loader->matter('matter-restricted');

        // Agenda search for the restricted title returns nothing for the
        // grantless org admin, in HTML and JSON, and counts exclude it.
        $search = $this->actingAs($admin)->getJson(route('calendar.agenda', [
            'mode' => 'list',
            'search' => $restrictedItem->title,
        ]));
        $search->assertOk();
        $this->assertSame([], $search->json('items'));
        $this->assertSame(0, $search->json('counts.total'));

        // HTML search page: the query itself is echoed in the search box
        // (the user's own input, not leaked content), so the leak pins are
        // the matter title's absence here and both titles' absence on the
        // unfiltered agenda page.
        $this->actingAs($admin)->get(route('calendar.index', [
            'mode' => 'list',
            'search' => $restrictedItem->title,
        ]))->assertOk()
            ->assertDontSee($restrictedMatter->title);

        $this->actingAs($admin)->get(route('calendar.index', ['mode' => 'list']))
            ->assertOk()
            ->assertDontSee($restrictedItem->title)
            ->assertDontSee($restrictedMatter->title);

        // Counts on the unfiltered agenda exclude restricted + rival items:
        // admin sees only the unrestricted Sterling matters (matter-1 + matter-2).
        $all = $this->actingAs($admin)->getJson(route('calendar.agenda', ['mode' => 'list']));
        $all->assertOk();
        $expected = CalendarItem::query()->whereIn('matter_id', [
            (string) $this->loader->matter('matter-1')->getKey(),
            (string) $this->loader->matter('matter-2')->getKey(),
        ])->count();
        $this->assertSame($expected, $all->json('counts.total'));
        $titles = collect($all->json('items'))->pluck('title')->all();
        $this->assertNotContains($restrictedItem->title, $titles);

        // Cross-org: rival's agenda contains none of Sterling's titles.
        $rivalAgenda = $this->actingAs($rival)->getJson(route('calendar.agenda', ['mode' => 'list']));
        $rivalAgenda->assertOk();
        $rivalTitles = collect($rivalAgenda->json('items'))->pluck('title')->all();
        $this->assertNotContains('Summary judgment response due', $rivalTitles);
        $this->assertNotContains($restrictedItem->title, $rivalTitles);

        // Export (Batch 5 / T-14): the grantless org admin's ICS export
        // contains the authorized matter-1 titles and NONE of the
        // restricted or rival titles.
        $export = $this->actingAs($admin)->get(route('calendar.export'));
        $export->assertOk();
        $exportBody = (string) $export->getContent();
        $this->assertStringContainsString('Summary judgment response due', $exportBody);
        $this->assertStringNotContainsString($restrictedItem->title, $exportBody);
        $this->assertStringNotContainsString($restrictedMatter->title, $exportBody);
        $this->assertStringNotContainsString('Rival Confidential Matter', $exportBody);

        // Rival's export contains none of Sterling's titles either.
        $rivalExport = $this->actingAs($rival)->get(route('calendar.export'));
        $rivalExport->assertOk();
        $rivalBody = (string) $rivalExport->getContent();
        $this->assertStringNotContainsString('Summary judgment response due', $rivalBody);
        $this->assertStringNotContainsString($restrictedItem->title, $rivalBody);

        // Feed (Batch 5 / T-14): the admin's own subscription feed is
        // re-scoped at fetch time — restricted items stay absent even
        // though the feed token itself is valid.
        $feed = $this->actingAs($admin)->postJson(route('calendar.feeds.store'));
        $feed->assertCreated();
        $feedBody = (string) $this->get('/feeds/calendar/'.$feed->json('token').'.ics')->assertOk()->getContent();
        $this->assertStringContainsString('Summary judgment response due', $feedBody);
        $this->assertStringNotContainsString($restrictedItem->title, $feedBody);
        $this->assertStringNotContainsString($restrictedMatter->title, $feedBody);

        // The revoked fixture feed 404s with no existence signal.
        $revokedToken = $this->loader->calendaringToken('feed-revoked');
        $this->assertNotNull($revokedToken);
        $this->get('/feeds/calendar/'.$revokedToken.'.ics')->assertNotFound();
    }
}
