<?php

use App\Http\Controllers\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\RequireOrgAdmin;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return view('welcome');
});

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

// Invitation landing (guest) + accept (auth). Token is email-bound:
// a different signed-in user is rejected generically (C-05).
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->middleware(['guest:'.config('fortify.guard')])
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
