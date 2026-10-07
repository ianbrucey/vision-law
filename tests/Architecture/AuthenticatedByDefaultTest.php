<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * C-14 door 3: authenticated-by-default.
 *
 * Every route EXCEPT the explicit guest list from 03-contract.md §Routes
 * carries `auth` middleware (or a Fortify equivalent that guarantees
 * authentication). A route carrying `guest` middleware must be on that
 * guest list — anything else is a fail-closed violation.
 *
 * Only registered routes are checked: GET view routes for login/register
 * are absent in headless Fortify mode (verified T-01), so absence is not a
 * failure.
 */
class AuthenticatedByDefaultTest extends TestCase
{
    /**
     * The exhaustive guest list (03-contract.md §Routes), as [METHOD, uri]
     * pairs. Verified against the contract — email verification is auth,
     * not guest; invitation accept is auth; logout is auth.
     */
    private const GUEST_ROUTES = [
        ['GET', 'login'],
        ['POST', 'login'],
        ['GET', 'register'],
        ['POST', 'register'],
        ['GET', 'forgot-password'],
        ['POST', 'forgot-password'],
        ['GET', 'reset-password/{token}'],
        ['POST', 'reset-password'],
        ['GET', 'two-factor-challenge'],
        ['POST', 'two-factor-challenge'],
        ['GET', 'invitations/{token}'],
    ];

    /**
     * Framework-owned routes outside the contract's route table, excluded
     * from this door with a recorded reason each:
     * - GET / ............ framework default landing page; static view with
     *                       zero data access (asserted 200 by ExampleTest)
     * - GET /up ........... Laravel health check (bootstrap/app.php
     *                       `health: '/up'`); public by design
     * - storage/{path} .... FilesystemServiceProvider::serveFiles for the
     *                       local disk (storage/app/private). No feature
     *                       writes there (door 1); recorded as an observation
     *                       in 07-evidence/route-review.md
     */
    private const FRAMEWORK_ROUTES = ['/', 'up', 'storage/{path}'];

    public function test_every_route_except_the_explicit_guest_list_requires_authentication(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (in_array($uri, self::FRAMEWORK_ROUTES, true)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $middleware = (array) $route->gatherMiddleware();

            $hasAuth = false;
            $hasGuest = false;
            foreach ($middleware as $entry) {
                if (! is_string($entry)) {
                    continue;
                }
                $name = explode(':', $entry)[0];
                if ($name === 'auth') {
                    $hasAuth = true;
                }
                if ($name === 'guest') {
                    $hasGuest = true;
                }
            }

            foreach ($methods as $method) {
                $onGuestList = in_array([$method, $uri], self::GUEST_ROUTES, true);

                if ($hasGuest) {
                    if (! $onGuestList) {
                        $failures[] = "{$method} /{$uri}: carries guest middleware but is not on the contract's guest list";
                    }

                    continue;
                }

                if (! $hasAuth && ! $onGuestList) {
                    $failures[] = "{$method} /{$uri}: no auth middleware and not on the guest list";
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Routes violating authenticated-by-default:\n".implode("\n", $failures)
        );
    }
}
