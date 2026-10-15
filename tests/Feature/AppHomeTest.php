<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 010 verdicts (00-brief.md §4): the app home, the layout-default
 * chrome, the auth-aware landing header, and the setup-mode regression.
 *
 * Fixtures: specs/010-app-home/04-fixtures.json via
 * FixtureLoader::loadHomeFixtures() — the single canonical graph (010-D06).
 * C-02 is BLOCKED (010-CONFLICT-01, decision 010-D03) — its test is
 * delivered skipped-with-reason, verdict NOT GREEN, rather than passing
 * silently or breaking the protected 003/005 assertions.
 */
class AppHomeTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = FixtureLoader::loadHomeFixtures();
    }

    /** C-01: /home is the authenticated app home. */
    public function test_home_requires_auth_and_renders_dashboard(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get(route('app.home'))->assertRedirect('/login');

        $this->actingAs($this->loader->user('user-home-admin'))
            ->get(route('app.home'))
            ->assertOk()
            ->assertSee('My matters')
            ->assertSee('Recent activity')
            ->assertSee('Security status');
    }

    /** C-02: login lands on /home; logout still lands on /. */
    public function test_login_redirects_to_home_logout_to_landing(): void
    {
        $this->markTestSkipped(
            'C-02 BLOCKED by 010-CONFLICT-01 (02-council.md, 010-D03): the brief orders the login '
            .'redirect changed to /home AND the 003 tests unchanged and green, but '
            .'PublicSiteAuthTest::test_login_with_valid_credentials_authenticates and the unmodifiable '
            .'TwoFactorScreensTest::test_non_admin_without_2fa_logs_in_normally both assert '
            .'POST /login → /. The assertions below are the brief\'s, ready to enable with the '
            .'recorded decision.'
        );

        // The brief's assertions (unreachable while C-02 is blocked):
        $this->post('/login', [
            'email' => 'h.admin@acme.example',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect('/home');

        $this->post(route('logout'))->assertRedirect('/');
    }

    /** C-03: dashboard v1 from existing data only. */
    public function test_home_shows_matters_activity_actions_and_security_status(): void
    {
        $admin = $this->loader->user('user-home-admin');

        $response = $this->actingAs($admin)->get(route('app.home'));
        $response->assertOk();

        // (a) My matters: both accessible matters, most recently updated
        // first, with the count and a link to the index.
        $response->assertViewHas('matterCount', 2);
        $response->assertSeeInOrder(['Acme v. Northwind Traders', 'Acme Lease Review']);
        $response->assertSee('MAT-2026-201');
        $response->assertSee('MAT-2026-202');
        $response->assertSee('href="'.route('matters.index').'"', false);

        // (b) Recent activity: the newest accessible event first (the
        // attorney's comment), with actor and matter surfaced.
        $response->assertSeeInOrder(['H. Attorney', 'commented on the matter']);
        $response->assertSeeInOrder(['commented on the matter', 'created the matter']);

        // (c) Quick actions: New matter; Invitations for org admins too.
        $response->assertSee('New matter');
        $response->assertSee('href="'.route('matters.create').'"', false);
        $response->assertSee('Invitations');
        $response->assertSee('href="'.route('admin.invitations.index').'"', false);

        // (d) Security status: confirmed 2FA, one passkey, link to the
        // security page.
        $response->assertSeeText('Two-factor authentication On');
        $response->assertSee('1 passkey registered');
        $response->assertSee('href="'.route('two-factor.settings').'"', false);

        // A non-admin gets no Invitations quick action.
        $attorney = $this->loader->user('user-home-attorney');
        $this->actingAs($attorney)->get(route('app.home'))
            ->assertOk()
            ->assertSee('New matter')
            ->assertDontSee('href="'.route('admin.invitations.index').'"', false);
    }

    /** C-04: guided empty state for a zero-matter organization. */
    public function test_home_empty_state_guides_first_run(): void
    {
        $lone = $this->loader->user('user-lone');

        $this->actingAs($lone)->get(route('app.home'))
            ->assertOk()
            ->assertSee('Welcome to Vision Law')
            ->assertSee('Create a matter')
            ->assertSee('href="'.route('matters.create').'"', false)
            ->assertDontSee('My matters')
            ->assertDontSee('Recent activity')
            ->assertDontSee('Upcoming deadlines');

        // An org admin of the empty org is additionally offered invites.
        $emptyAdmin = $this->loader->user('user-empty-admin');
        $this->actingAs($emptyAdmin)->get(route('app.home'))
            ->assertOk()
            ->assertSee('Invite your team')
            ->assertSee('href="'.route('admin.invitations.index').'"', false);
    }

    /** C-05: the chrome becomes the layout default. */
    public function test_app_chrome_renders_nav_and_user_menu_on_all_app_pages(): void
    {
        $admin = $this->loader->user('user-home-admin');
        $matter = $this->loader->matter('matter-h1');

        $pages = [
            'home' => route('app.home'),
            'matters index' => route('matters.index'),
            'matter show' => route('matters.show', $matter),
            // "Document search" is not enumerated: /search/documents is a
            // JSON-only API, not a page (010-CONFLICT-03 / 010-D09).
            'templates' => route('templates.index'),
            'security page' => route('two-factor.settings'),
            'invitations' => route('admin.invitations.index'),
        ];

        foreach ($pages as $label => $url) {
            $response = $this->actingAs($admin)->get($url);
            $response->assertOk("{$label} did not render 200");
            $this->assertChrome($response, $label, admin: true, userName: 'H. Admin');
        }

        // Non-admin chrome: role-gated entries are absent, sign-out stays.
        $attorney = $this->loader->user('user-home-attorney');
        $response = $this->actingAs($attorney)->get(route('matters.index'));
        $response->assertOk();
        $this->assertChrome($response, 'matters index (attorney)', admin: false, userName: 'H. Attorney');
    }

    /** C-06: sign out from any app page ends the session. */
    public function test_sign_out_from_any_app_page_ends_session(): void
    {
        $admin = $this->loader->user('user-home-admin');

        // The sign-out control is present on app pages generally...
        $this->actingAs($admin)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false);

        // ...and using it ends the session and lands on /, per the
        // unchanged logout redirect.
        $this->actingAs($admin)->get(route('matters.index'))->assertOk();
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->get(route('app.home'))->assertRedirect('/login');
    }

    /** C-07: the public landing header reflects auth state. */
    public function test_landing_header_reflects_auth_state(): void
    {
        // Guest: exactly the 003 header — Sign in, no Open app, no
        // sign-out form.
        $this->get('/')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Open app')
            ->assertDontSee('action="'.route('logout').'"', false);

        // Signed in: within the header nav, Open app (→ /home) and a Sign
        // out form replace Sign in. (The hero's Sign in CTA is body
        // content, outside C-07's header scope, and is untouched.)
        $admin = $this->loader->user('user-home-admin');
        $response = $this->actingAs($admin)->get('/');
        $response->assertOk()
            ->assertSee('Open app')
            ->assertSee('action="'.route('logout').'"', false);

        $html = (string) $response->getContent();
        $navStart = strpos($html, '<nav');
        $navEnd = strpos($html, '</nav>');
        $this->assertTrue($navStart !== false && $navEnd !== false, 'landing header nav not found');
        $header = substr($html, $navStart, $navEnd - $navStart);

        $this->assertStringContainsString('Open app', $header);
        $this->assertStringContainsString('href="'.route('app.home').'"', $header);
        $this->assertStringContainsString('action="'.route('logout').'"', $header);
        $this->assertStringNotContainsString('Sign in', $header);
    }

    /** C-08: setup mode untouched — /home is not on the 005 allowlist. */
    public function test_setup_mode_still_confined_until_2fa_confirmed(): void
    {
        $admin = $this->loader->user('user-home-admin');

        // A setup-mode-flagged session is turned back to the enrollment
        // surface — /home was NOT added to the allowlist.
        $this->actingAs($admin)
            ->withSession([RestrictToTwoFactorSetup::SESSION_KEY => true])
            ->get(route('app.home'))
            ->assertRedirect(route('two-factor.settings'));

        // After 2FA is confirmed and the flag has cleared, /home renders.
        $this->actingAs($admin)
            ->withSession([RestrictToTwoFactorSetup::SESSION_KEY => false])
            ->get(route('app.home'))
            ->assertOk();
    }

    /** C-09: home leaks nothing across matter access. */
    public function test_home_scopes_to_matter_access(): void
    {
        // Org admin: only own-org data — the other org's (more recent)
        // matter contributes neither title, number, count, nor activity.
        $admin = $this->loader->user('user-home-admin');
        $response = $this->actingAs($admin)->get(route('app.home'));
        $response->assertOk()
            ->assertViewHas('matterCount', 2)
            ->assertViewHas('activity', fn ($activity) => $activity->count() === 3)
            ->assertDontSee('Other Confidential Engagement')
            ->assertDontSee('MAT-2026-301');

        // Grant-less viewer in the same org: the org has matters, the
        // user accesses none — nothing leaks.
        $viewer = $this->loader->user('user-home-viewer');
        $this->actingAs($viewer)->get(route('app.home'))
            ->assertOk()
            ->assertViewHas('matterCount', 0)
            ->assertDontSee('Acme v. Northwind Traders')
            ->assertDontSee('Acme Lease Review')
            ->assertSee('No matters are shared with you yet');

        // The other org's admin sees exactly their own matter.
        $other = $this->loader->user('user-other-admin');
        $this->actingAs($other)->get(route('app.home'))
            ->assertOk()
            ->assertViewHas('matterCount', 1)
            ->assertSee('Other Confidential Engagement')
            ->assertDontSee('Acme v. Northwind Traders');
    }

    /**
     * The C-05 chrome contract on one rendered page: full nav for the
     * acting role, user name + org, security settings, sign-out form.
     */
    private function assertChrome(TestResponse $response, string $label, bool $admin, string $userName): void
    {
        $html = (string) $response->getContent();

        $navRoutes = ['app.home', 'matters.index', 'templates.index'];
        if ($admin) {
            $navRoutes[] = 'admin.invitations.index';
            $navRoutes[] = 'admin.retention.policies.index';
        }

        foreach ($navRoutes as $routeName) {
            $this->assertStringContainsString(
                'href="'.route($routeName).'"',
                $html,
                "{$label}: missing nav link for {$routeName}"
            );
        }

        if (! $admin) {
            $this->assertStringNotContainsString('href="'.route('admin.invitations.index').'"', $html, "{$label}: invitations link leaked to non-admin");
            $this->assertStringNotContainsString('href="'.route('admin.retention.policies.index').'"', $html, "{$label}: retention link leaked to non-admin");
        }

        $this->assertStringContainsString($userName, $html, "{$label}: missing user name in the user menu");
        $this->assertStringContainsString('Acme Legal Group', $html, "{$label}: missing organization in the chrome");
        $this->assertStringContainsString('Security settings', $html, "{$label}: missing security settings entry");
        $this->assertStringContainsString('action="'.route('logout').'"', $html, "{$label}: missing sign-out form");
        $this->assertStringContainsString('Sign out', $html, "{$label}: missing sign-out control");
    }
}
