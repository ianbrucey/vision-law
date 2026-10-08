<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\View\Components\Ui\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 006 T-05 verdict tests — matter UI screens (05-ui.md).
 *
 * Blade screens only; the JSON contract is covered by the T-02/T-04 suites
 * (which keep passing — content negotiation is by Accept header).
 */
class MatterUiTest extends TestCase
{
    use RefreshDatabase;

    private function htmlHeaders(): array
    {
        return ['Accept' => 'text/html'];
    }

    public function test_index_renders_list_with_search_and_filters(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $matter = $loader->matter('matter-1');

        $response = $this->actingAs($admin)->get('/matters', $this->htmlHeaders());

        $response->assertOk();
        // Search box + filter chips + table per 05-ui.md / mockup §01.
        $response->assertSee('Search matters', false);
        $response->assertSee('All states', false);
        $response->assertSee('Matter type', false);
        $response->assertSee('Assignee', false);
        $response->assertSee(e($matter->matter_number), false);
        $response->assertSee(e($matter->title), false);
        // Status renders through <x-ui.status>, never as raw enum text.
        $response->assertSee('Intake', false);
        $response->assertDontSee('>INTAKE<', false);
    }

    public function test_index_empty_state(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $viewer = $loader->user('user-viewer'); // unassigned: "My Matters" is empty

        $response = $this->actingAs($viewer)->get('/matters', $this->htmlHeaders());

        $response->assertOk();
        $response->assertSee('No matters yet', false);
        $response->assertDontSee('Sterling v. Apex Construction', false);
    }

    public function test_index_filter_by_state(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');
        $matter = $loader->matter('matter-1');

        $response = $this->actingAs($admin)
            ->get('/matters?state='.$matter->lifecycle_state, $this->htmlHeaders());

        $response->assertOk();
        $response->assertSee(e($matter->matter_number), false);
    }

    public function test_show_renders_all_four_tabs(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $mid = (string) $loader->matter('matter-1')->getKey();

        foreach (['overview', 'documents', 'timeline', 'team'] as $tab) {
            $response = $this->actingAs($attorney)
                ->get("/matters/{$mid}?tab={$tab}", $this->htmlHeaders());

            $response->assertOk();
            $response->assertSee('Overview', false);
            $response->assertSee('Documents', false);
            $response->assertSee('Timeline', false);
            $response->assertSee('Team', false);
        }
    }

    public function test_show_documents_tab_is_phase3_placeholder_with_real_log(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $mid = (string) $loader->matter('matter-1')->getKey();

        $response = $this->actingAs($attorney)
            ->get("/matters/{$mid}?tab=documents", $this->htmlHeaders());

        $response->assertOk();
        // Explicit Phase-3 placeholder — defined empty panel, not a dead link.
        $response->assertSee('Document management arrives in Phase 3', false);
        // The received/sent register is real (this spec).
        $response->assertSee('Received / sent log', false);
    }

    public function test_create_form_renders_with_inline_error_support(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');

        $response = $this->actingAs($admin)->get('/matters/create', $this->htmlHeaders());

        $response->assertOk();
        $response->assertSee('New matter', false);
        $response->assertSee('name="title"', false);
        $response->assertSee('name="matter_type"', false);
        $response->assertSee('name="client_name"', false);
    }

    public function test_store_html_redirects_with_toast_naming_consequence(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');

        $response = $this->actingAs($admin)->post('/matters', [
            'title' => 'UI Test v. Fixture',
            'matter_type' => 'litigation',
            'client_name' => 'UI Test Client',
        ], $this->htmlHeaders());

        $matter = Matter::query()->where('title', 'UI Test v. Fixture')->firstOrFail();

        $response->assertRedirect(route('matters.show', $matter));
        $response->assertSessionHas('toast.tone', 'ok');
        $this->assertStringContainsString(
            (string) $matter->matter_number,
            (string) session('toast')['message']
        );
    }

    public function test_store_html_validation_errors_redirect_back_with_errors(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');

        // 05-ui.md §05 error state: inline field errors with old input.
        $response = $this->actingAs($admin)
            ->from('/matters/create')
            ->post('/matters', ['title' => ''], $this->htmlHeaders());

        $response->assertRedirect('/matters/create');
        $response->assertSessionHasErrors(['title', 'matter_type', 'client_name']);
    }

    public function test_denied_page_is_generic_with_no_existence_leak(): void
    {
        // Mockup §06: one page for every denial class — no distinguishing
        // detail, no matter data.
        $view = $this->view('matters.denied', []);

        $view->assertSee('Not found', false);
        $view->assertSee("This matter doesn't exist or you don't have access to it.", false);
        $view->assertDontSee('Sterling v. Apex Construction', false);
        $view->assertDontSee('MAT-', false);
    }

    public function test_status_component_renders_all_eight_lifecycle_states(): void
    {
        $expected = [
            'INTAKE' => 'Intake',
            'ACTIVE' => 'Active',
            'DISCOVERY' => 'Discovery',
            'PRE_TRIAL' => 'Pre-trial',
            'TRIAL_SETTLEMENT' => 'Trial / settlement',
            'CLOSED' => 'Closed',
            'RETENTION_HOLD' => 'Retention hold',
            'DISPOSITION' => 'Disposition',
        ];

        $this->assertSame(array_keys($expected), array_keys(Status::MAP['matter']));

        foreach ($expected as $value => $label) {
            $this->blade('<x-ui.status type="matter" :value="$value" />', ['value' => $value])
                ->assertSee($label, false)
                ->assertDontSee($value, false);
        }
    }

    public function test_json_responses_still_work(): void
    {
        // The T-02/T-04 contract is untouched: getJson keeps returning JSON.
        $loader = FixtureLoader::loadMatterFixtures();
        $admin = $loader->user('user-admin');

        $this->actingAs($admin)->getJson('/matters')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->actingAs($admin)->getJson('/matters/create')->assertOk()->assertJsonStructure(['matter_types', 'lifecycle_states']);
    }
}
