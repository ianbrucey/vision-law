<?php

use App\Http\Controllers\Admin\AuditEventController as AdminAuditEventController;
use App\Http\Controllers\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MatterController;
use App\Http\Controllers\MatterGrantController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\RequireOrgAdmin;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// ── Spec 003 T-05: public site + auth views ──
// Fortify is headless (config/fortify.php: views => false), so it registers
// no GET view routes -- these are app-level. The POST backends stay
// Fortify's, untouched (see the auth block below).
Route::get('/', function () {
    return view('public.landing');
});

Route::get('/login', [LoginController::class, 'create'])
    ->middleware(['guest:'.config('fortify.guard')])
    ->name('login');

// Same FORTIFY_REGISTRATION flag gate as the POST route below (001-D09):
// with the flag off, neither the view nor the endpoint is registered (404).
if (Features::enabled(Features::registration())) {
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->middleware(['guest:'.config('fortify.guard')])
        ->name('register');
}

// ── Auth (Fortify headless backend; 03-contract.md §Routes guest list) ──
// Fortify registers its auth POST/PUT/DELETE routes itself — headless mode
// means no GET view routes (verified T-01). The routes below are app-level
// overrides, registered after Fortify's so they take precedence:
//
// - POST /register: Fortify's contract, but a fresh registration does NOT
//   auto-login — unverified accounts cannot log in (C-01). Registered only
//   when the FORTIFY_REGISTRATION flag is on (001-D09).
// - POST /forgot-password: generic success whether or not the email belongs
//   to an account — no account enumeration (C-04).
// - POST /two-factor-challenge: Fortify's challenge with the brute-force
//   lockout applied (C-03).
// - GET /reset-password/{token}: named route only, so the reset notification
//   can generate its URL; the headless API renders no reset form.
if (Features::enabled(Features::registration())) {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard')])
        ->name('register.store');
}

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:6,1'])
    ->name('password.email');

Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:10,1'])
    ->name('two-factor.login.store');

Route::get('/reset-password/{token}', function () {
    return response()->json([
        'message' => 'Password reset is handled by the client application: POST /reset-password with token, email and new password.',
    ]);
})->middleware(['guest:'.config('fortify.guard')])->name('password.reset');

// ── Ticket 5: RBAC admin, invitations, session management (backend only,
// no Blade per 001-D06) ──

// Invitation landing + accept (auth). The token is the credential, so the
// landing page is reachable by guests and signed-in users alike (004-D08):
// after signing in via the ?next= link, an existing invitee lands back on
// the accept page instead of being bounced to /home by guest middleware.
// A different signed-in user is rejected generically (C-05); acceptance
// still enforces the email/org match rule.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->name('invitations.show');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware(['auth:'.config('fortify.guard')])
    ->name('invitations.accept');

// Session management (C-07): list is self-service; destructive actions sit
// behind password.confirm.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('/sessions', [SessionController::class, 'destroyAll'])
        ->middleware('password.confirm')
        ->name('sessions.destroyAll');
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy'])
        ->middleware('password.confirm')
        ->name('sessions.destroy');
});

// Admin section (03-contract.md §Routes): org_admin only — 403 otherwise,
// audited as user.admin.denied. Every query is scoped to the admin's org,
// so the system org (001-D13) can never appear nor receive users.
Route::middleware(['auth:'.config('fortify.guard'), RequireOrgAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::match(['put', 'patch'], '/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        Route::get('/teams', [AdminTeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [AdminTeamController::class, 'store'])->name('teams.store');
        Route::get('/teams/{team}', [AdminTeamController::class, 'show'])->name('teams.show');
        Route::match(['put', 'patch'], '/teams/{team}', [AdminTeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [AdminTeamController::class, 'destroy'])->name('teams.destroy');

        Route::get('/invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
        Route::post('/invitations', [AdminInvitationController::class, 'store'])->name('invitations.store');
        Route::delete('/invitations/{invitation}', [AdminInvitationController::class, 'destroy'])->name('invitations.destroy');
    });

// ── Ticket 7: audit viewer (backend only, no Blade per 001-D06) ──
// Denials on these routes are audited as audit.viewer.denied (03-contract.md
// §Error catalog), distinct from the generic user.admin.denied on the other
// admin routes. The export additionally sits behind password.confirm.
// Queries are scoped to the admin's org (001-D13: system-org events are out
// of scope for the org viewer).
Route::middleware(['auth:'.config('fortify.guard'), RequireOrgAdmin::class.':audit.viewer.denied'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/audit-events', [AdminAuditEventController::class, 'index'])->name('audit-events.index');
        Route::get('/audit-events/export', [AdminAuditEventController::class, 'export'])
            ->middleware('password.confirm')
            ->name('audit-events.export');
    });

// ── Ticket 6: matter grants + authorization proving ground (backend only,
// no Blade per 001-D06) ──
// RequireMatterAccess resolves the matter and authorizes BEFORE any data
// access; controllers read the authorized matter from request attributes.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/matters/{matter}', [MatterController::class, 'show'])
        ->middleware('matter.access:view')
        ->name('matters.show');

    Route::post('/matters/{matter}/grants', [MatterGrantController::class, 'store'])
        ->middleware('matter.access:grant')
        ->name('matters.grants.store');

    Route::delete('/matters/{matter}/grants/{grant}', [MatterGrantController::class, 'destroy'])
        ->middleware('matter.access:grant')
        ->name('matters.grants.destroy');
});

// ── Spec 002 T-05: /_patterns catalogue (local only) ──
// Renders every x-ui primitive in every variant/state with synthetic data —
// the living drift detector (docs/UI_Standards.md Governance). 404s outside
// the local environment (C-08). Implemented as an env-gated abort inside the
// route (rather than conditional registration) so the suite can assert both
// states; externally it is unreachable in production either way. Carries auth
// like every other non-guest route (AuthenticatedByDefaultTest door) — in
// production an authenticated hit still 404s on the env gate.
Route::get('/_patterns', function () {
    abort_unless(app()->environment('local'), 404);

    $documents = collect(range(1, 42))->map(fn (int $i): array => [
        'title' => "Synthetic document {$i}",
        'status' => ['draft', 'final', 'superseded'][$i % 3],
        'modified' => 'Oct '.(1 + ($i % 6)).', 2026',
        'by' => $i % 2 === 0 ? 'G. Granted' : 'P. Paralegal',
    ]);
    $perPage = 15;
    $pageItems = $documents->forPage(2, $perPage)->values();
    $paginator = new LengthAwarePaginator($pageItems, $documents->count(), $perPage, 2, ['path' => '/_patterns']);

    // Rendered by <x-ui.toast /> in the patterns page (re-flashed per tone so
    // every tone is demonstrated from the real session-flash path).
    $toastDemos = [
        ['tone' => 'ok', 'message' => 'Draft exported — PDF saved to Sterling v. Apex › Documents.'],
        ['tone' => 'bad', 'message' => 'Upload failed — contract-scan.pdf exceeds the 50 MB limit.'],
        ['tone' => 'info', 'message' => 'Deadline recalculated — discovery cutoff moved to Nov 12.'],
    ];

    return view('patterns', [
        'documents' => $pageItems,
        'paginator' => $paginator,
        'toastDemos' => $toastDemos,
    ]);
})->middleware('auth:'.config('fortify.guard'))->name('patterns');
