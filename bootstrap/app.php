<?php

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DocumentFilingException;
use App\Exceptions\InvitationInvalidException;
use App\Exceptions\MatterStateException;
use App\Http\Middleware\EnsureSessionLifetime;
use App\Http\Middleware\RequireMatterAccess;
use App\Http\Middleware\RestrictToTwoFactorSetup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // T-06: matter-route authorization (RequireMatterAccess:<action>)
        // runs before any data access on /matters/* routes.
        $middleware->alias([
            'matter.access' => RequireMatterAccess::class,
            // Spec 005 (005-D01): restricted setup-mode session for org
            // admins without confirmed 2FA. Explicit allowlist — enforced
            // in the middleware, not a denylist.
            '2fa.setup' => RestrictToTwoFactorSetup::class,
        ]);

        // C-07: absolute session lifetime for privileged roles (org_admin +
        // attorney). No-op for guests and non-privileged roles.
        $middleware->web(append: [EnsureSessionLifetime::class]);

        // Spec 005 (005-D01): on the web group so EVERY web route is
        // covered by default, including routes added later. Runs before the
        // per-route auth middleware, but the flag implies an authenticated
        // session (it is set only on login), so the outcome is identical.
        // No-op for sessions without the setup-mode flag.
        $middleware->web(append: ['2fa.setup']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 03-contract.md error catalog: every validation failure renders as
        // {code: "validation", details: {...}} for JSON clients.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code' => 'validation',
                    'details' => $e->errors(),
                ], 422);
            }
        });

        // 03-contract.md: cross-org / missing resources render as
        // {code: "not_found"} for JSON clients (no existence leak).
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'not_found'], 404);
            }
        });

        // C-05: every invitation failure mode (unknown/expired/revoked/
        // accepted token, wrong signed-in user) renders the same generic
        // body — no enumeration. JSON-only surface (no Blade per 001-D06).
        $exceptions->render(function (InvitationInvalidException $e) {
            return response()->json(['code' => 'invitation_invalid'], 422);
        });

        // Spec 006 T-02: domain lifecycle failures render the contract
        // error-catalog body ({code, ...context}) for JSON clients.
        $exceptions->render(function (MatterStateException $e) {
            return response()->json($e->body(), $e->httpStatus);
        });

        // Spec 006 T-02: service-level authorization denials (create,
        // delete, restore) render the same bodies RequireMatterAccess
        // produces — {code: "forbidden"} / {code: "not_found"}.
        // Spec 007 T-05: domain filing failures render the contract
        // error-catalog body ({code, ...context}) for JSON clients.
        $exceptions->render(function (DocumentFilingException $e) {
            return response()->json($e->body(), $e->httpStatus);
        });

        $exceptions->render(function (AccessDeniedException $e) {
            return response()->json(
                ['code' => $e->httpStatus === 404 ? 'not_found' : 'forbidden'],
                $e->httpStatus
            );
        });
    })->create();
