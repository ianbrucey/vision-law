<?php

namespace Tests\Feature;

use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 012 — the front-desk chat panel on the app home (Variant A).
 *
 * Front-end only, like Slice 1: the panel renders a labeled sample
 * conversation on /home; the one live binding is the matter-route card,
 * which proposes the user's first accessible matter (HomeController's
 * accessibleBy scope, read-only) and links the real workspace route.
 * Chat persistence and the agent arrive in later slices.
 */
class FrontDeskTest extends TestCase
{
    use RefreshDatabase;

    private function htmlHeaders(): array
    {
        return ['Accept' => 'text/html'];
    }

    public function test_home_renders_front_desk_with_route_card_bound_to_accessible_matter(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');

        // The card binds the first matter in the same scope/order the
        // HomeController assembles for the My matters module.
        $expected = Matter::query()
            ->accessibleBy($attorney)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($expected);

        $response = $this->actingAs($attorney)
            ->get('/home', $this->htmlHeaders());

        $response->assertOk();
        // The panel and its sample front-desk thread.
        $response->assertSee('Front desk', false);
        $response->assertSee('General chat', false);
        $response->assertSee('What do I have going on today?', false);
        $response->assertSee('Calendar · today', false);
        $response->assertSee('Needs attention', false);
        $response->assertSee('Sample preview', false);
        // The route card binds the real accessible matter.
        $response->assertSee('Likely matter', false);
        $response->assertSee(e($expected->title), false);
        $response->assertSee(e($expected->matter_number), false);
        $response->assertSee('data-fd-open', false);
        // The in-place session state is rendered (hidden) with the carried
        // excerpt and the real link to the full workspace.
        $response->assertSee('In session', false);
        $response->assertSee('Brought from front desk', false);
        $response->assertSee('Open the full workspace', false);
        $response->assertSee('href="'.route('matters.workspace', $expected).'"', false);
        // The panel does not displace the spec 010 dashboard modules.
        $response->assertSee('My matters', false);
    }

    public function test_front_desk_falls_back_to_sample_card_without_accessible_matter(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $viewer = $loader->user('user-viewer'); // org has matters; no grants

        $this->assertSame(
            0,
            Matter::query()->accessibleBy($viewer)->count(),
            'Fixture premise: the viewer can access no matters.'
        );

        $response = $this->actingAs($viewer)
            ->get('/home', $this->htmlHeaders());

        $response->assertOk();
        $response->assertSee('Front desk', false);
        // Graceful fallback: sample card, explicitly no live link, and no
        // session state is rendered at all.
        $response->assertSee('Sample matter — wage claim', false);
        $response->assertSee('no live link', false);
        $response->assertDontSee('data-fd-open', false);
        $response->assertDontSee('Brought from front desk', false);
        $response->assertDontSee('Open the full workspace', false);
    }

    public function test_front_desk_requires_authentication(): void
    {
        $this->get('/home', $this->htmlHeaders())
            ->assertRedirect('/login');
    }
}
