<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 012 Slice 1 — the Glass/Aurora matter workspace.
 *
 * The surface renders the real matter's identity with the controller's
 * demo case content, behind the same RequireMatterAccess :view gate as
 * the matter detail screen. Rendered text is product copy only — no
 * preview/sample commentary (2026-11-05). Chat persistence and Brain
 * data arrive in later slices.
 */
class MatterWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function htmlHeaders(): array
    {
        return ['Accept' => 'text/html'];
    }

    public function test_workspace_renders_for_authorized_user(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $matter = $loader->matter('matter-1');

        $response = $this->actingAs($attorney)
            ->get("/matters/{$matter->getKey()}/workspace", $this->htmlHeaders());

        $response->assertOk();
        // The real matter's identity binds in the header.
        $response->assertSee(e($matter->matter_number), false);
        $response->assertSee(e($matter->title), false);
        // No dev/meta commentary is rendered (Ian's standing rule).
        $response->assertDontSee('Sample case data', false);
        $response->assertDontSee('UX preview', false);
        $response->assertDontSee('ga-chat-note', false);
        // Position rail + active protocol.
        $response->assertSee('Case Position', false);
        $response->assertSee('Active protocol: ASSESSMENT', false);
        // Workspace panes.
        $response->assertSee('Battlefield', false);
        $response->assertSee('Claim 1 — Unpaid wages', false);
        $response->assertSee('Text thread — Romine', false);
        $response->assertSee('Client narrative — recorded at intake', false);
        $response->assertSee('Verification passes', false);
        // Chat + checkpoint surface.
        $response->assertSee('Agent — working this case with you', false);
        $response->assertSee('Checkpoint — your call required', false);
        $response->assertSee('Hold it pending discovery', false);
    }

    public function test_workspace_hidden_from_user_without_matter_access(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $viewer = $loader->user('user-viewer'); // no grant on matter-1
        $matter = $loader->matter('matter-1');

        $response = $this->actingAs($viewer)
            ->get("/matters/{$matter->getKey()}/workspace", $this->htmlHeaders());

        // RequireMatterAccess leak rule: no grant → 404, no existence signal.
        $response->assertNotFound();
        $response->assertDontSee(e($matter->title), false);
    }

    public function test_workspace_requires_authentication(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $matter = $loader->matter('matter-1');

        $this->get("/matters/{$matter->getKey()}/workspace", $this->htmlHeaders())
            ->assertRedirect('/login');
    }
}
