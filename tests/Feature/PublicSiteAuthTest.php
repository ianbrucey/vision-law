<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 003 T-06 — verdict sweep over the public site + auth pages (claims
 * C-01–C-05). Every brief verdict lives here as a named test, run against
 * the T-05-wired app.
 *
 * Conventions follow 001's AuthFlowsTest: PHPUnit classes, RefreshDatabase,
 * 001 fixtures via FixtureLoader (no 003-specific fixtures exist), the HIBP
 * k-anonymity API faked per test.
 *
 * NOTE (from T-05): a pre-existing out-of-scope issue — browser (non-JSON)
 * login for a 2FA-enabled user 500s (Route [two-factor.login] not defined;
 * the 2FA challenge view is a deferred follow-up spec). The login tests
 * here always use the non-2FA `user_viewer` fixture (vera@sterling.test),
 * which is verified and 2FA-free per FixtureLoader.
 */
class PublicSiteAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same stray-request guard as AuthFlowsTest: the registration flow
        // consults the HIBP k-anonymity API, which must stay faked.
        Http::preventStrayRequests();
        $this->fakeHibp();
    }

    /**
     * Fake the HIBP k-anonymity API: every prefix reports clean.
     */
    private function fakeHibp(): void
    {
        Http::fake([
            'https://api.pwnedpasswords.com/range/*' => Http::response(
                "0018A45C4D1DEF81644B54AB7F969B88D65:2\n",
                200
            ),
        ]);
    }

    /**
     * Load fixtures and open the sterling org for self-registration.
     */
    private function makeOpenOrg(): Organization
    {
        $fixtures = FixtureLoader::load();
        $org = $fixtures->org('org_sterling');
        $org->update(['settings' => ['registration_mode' => 'open']]);

        return $org;
    }

    /**
     * C-01: the landing page renders with every approved mockup section.
     */
    public function test_landing_renders_with_all_mockup_sections(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // Responsive contract: the viewport meta (the 390px phone check is
        // a markup contract — viewport meta + no fixed-width containers).
        $response->assertSee('<meta name="viewport"', false);

        // Hero: the chrome wordmark image.
        $response->assertSee('/images/vision-wordmark.png', false);

        // Trust strip items.
        $response->assertSee('Encryption everywhere');
        $response->assertSee('Append-only audit trail');
        $response->assertSee('SSO & SCIM ready');
        $response->assertSee('Role-based access');

        // Platform grid: all six feature names.
        foreach ([
            'Matter management',
            'Document intelligence',
            'Deadline engine',
            'Drafting copilot',
            'Outside-counsel sharing',
            'Retention & legal holds',
        ] as $feature) {
            $response->assertSee($feature);
        }

        // Auditability section.
        $response->assertSee('Prove it, don’t promise it.');
        $response->assertSee('AUDIT TRAIL · MAT-2026-001 · LIVE');

        // Testimonial + its synthetic-quote badge.
        $response->assertSee('We replaced four systems with Vision Law.');
        $response->assertSee('SYNTHETIC PLACEHOLDER — NOT A REAL QUOTE');

        // Final CTA + footer.
        $response->assertSee('See what your practice is missing.');
        $response->assertSee('© 2026 Vision Law. All rights reserved.');
        $response->assertSee('SYNTHETIC DATA ONLY');
    }

    /**
     * C-02: GET /login renders the sign-in form for guests.
     */
    public function test_login_page_renders_for_guests(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $response->assertSee('Welcome back');
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);

        // The form targets Fortify's existing login endpoint (POST /login).
        $this->assertSame(url('/login'), route('login.store'));
        $response->assertSee('method="POST"', false);
    }

    /**
     * C-02: POST /login with valid credentials authenticates (browser flow)
     * and redirects to / (003-D02). Non-2FA fixture only — see the class
     * docblock for the deferred 2FA-challenge-view issue.
     */
    public function test_login_with_valid_credentials_authenticates(): void
    {
        $fixtures = FixtureLoader::load();
        $user = $fixtures->user('user_viewer');

        $this->assertNull($user->two_factor_secret);

        $response = $this->post('/login', [
            'email' => 'vera@sterling.test',
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * C-03: GET /register renders the registration form for guests — with
     * no org-name field (003-O1, spec default: the backend never creates an
     * org, so the form must not promise one).
     */
    public function test_register_page_renders_for_guests(): void
    {
        $response = $this->get('/register');
        $response->assertOk();

        $response->assertSee('Create your account');
        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);

        // The form targets the existing Fortify endpoint (POST /register).
        $this->assertSame(url('/register'), route('register.store'));
        $response->assertSee('method="POST"', false);

        // 003-O1: no org-name field is submitted.
        $response->assertDontSee('Organization name');
    }

    /**
     * C-03: POST /register creates the user per the 001 CreateNewUser
     * contract (resolved org, least-privileged role) with NO auto-login
     * for the unverified account — the browser flow lands on /login with
     * the verification toast (003-D02).
     */
    public function test_register_creates_user_without_auto_login(): void
    {
        $org = $this->makeOpenOrg();

        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Wendy Websign',
            'email' => 'wendy.websign@example.test',
            'password' => 'Correct-Horse-99-Battery',
            'password_confirmation' => 'Correct-Horse-99-Battery',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas(
            'toast.message',
            'Check your email to verify your account, then sign in.'
        );
        $this->assertGuest();

        $user = User::where('email', 'wendy.websign@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame((string) $org->getKey(), (string) $user->org_id);
        $this->assertTrue($user->hasRole('viewer'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * C-04: public pages carry no auth session leakage — no user email,
     * name, org name, or session value in the HTML for any actor — and the
     * GET routes sit behind the guest middleware.
     */
    public function test_public_pages_have_no_user_data(): void
    {
        FixtureLoader::load();

        foreach (['/', '/login', '/register'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            foreach ([
                'vera@sterling.test',
                'admin@sterling.test',
                'grace@sterling.test',
                'Vera Viewer',
                'Ada Admin',
                'Grace Granted',
            ] as $sentinel) {
                $this->assertStringNotContainsString(
                    $sentinel,
                    $html,
                    "Leak sentinel '{$sentinel}' found in {$path}"
                );
            }
        }

        // The view routes are guest-gated (authenticated users bounce).
        foreach (['login', 'register'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains(
                'guest:'.config('fortify.guard'),
                $route->gatherMiddleware(),
                "Route {$name} is not guest-gated"
            );
        }
    }

    /**
     * C-04: signed-in users are redirected off the guest pages, never 200.
     */
    public function test_authenticated_users_redirected_off_guest_pages(): void
    {
        $fixtures = FixtureLoader::load();
        $user = $fixtures->user('user_viewer');

        $this->actingAs($user)->get('/login')->assertRedirect('/');
        $this->actingAs($user)->get('/register')->assertRedirect('/');
    }

    /**
     * C-05: logo assets exist on disk (non-empty) and the pages reference
     * them. Static files are served by the web server (Caddy), not the
     * Laravel router, so there is no in-process GET → 200 assertion here —
     * the page contract is that the markup references the asset paths.
     */
    public function test_logo_assets_served(): void
    {
        foreach (['vision-wordmark.png', 'vision-emblem.png'] as $asset) {
            $path = public_path('images/'.$asset);
            $this->assertFileExists($path);
            $this->assertGreaterThan(
                0,
                filesize($path),
                "{$asset} must be non-empty"
            );
        }

        $this->get('/')->assertOk()->assertSee('/images/vision-wordmark.png', false);
        $this->get('/login')->assertOk()->assertSee('/images/vision-emblem.png', false);
    }

    /**
     * C-02/C-03: the GET view routes are registered without Fortify name
     * collisions; the POST backends keep their names and methods.
     */
    public function test_guest_routes_registered_without_collision(): void
    {
        foreach (['login', 'register', 'login.store', 'register.store'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing");
        }

        $login = Route::getRoutes()->getByName('login');
        $this->assertSame('login', $login->uri());
        $this->assertSame(['GET', 'HEAD'], $login->methods());

        $register = Route::getRoutes()->getByName('register');
        $this->assertSame('register', $register->uri());
        $this->assertSame(['GET', 'HEAD'], $register->methods());

        $loginStore = Route::getRoutes()->getByName('login.store');
        $this->assertSame(['POST'], $loginStore->methods());

        $registerStore = Route::getRoutes()->getByName('register.store');
        $this->assertSame(['POST'], $registerStore->methods());
    }
}
