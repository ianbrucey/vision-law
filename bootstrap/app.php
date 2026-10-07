<?php

use App\Exceptions\InvitationInvalidException;
use App\Http\Middleware\EnsureSessionLifetime;
use App\Http\Middleware\RequireMatterAccess;
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
        ]);

        // C-07: absolute session lifetime for privileged roles (org_admin +
        // attorney). No-op for guests and non-privileged roles.
        $middleware->web(append: [EnsureSessionLifetime::class]);
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
    })->create();
