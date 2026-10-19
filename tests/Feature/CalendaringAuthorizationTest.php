<?php

namespace Tests\Feature;

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
}
