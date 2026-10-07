<?php

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
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
