<?php

use App\Http\Controllers\Internal\MattersToolController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Spec 012 — internal API (agent service only)
|--------------------------------------------------------------------------
|
| These routes are called by the private agent service, never by a
| browser. Every handler verifies the shared internal token itself
| (see MattersToolController). No session auth applies here.
|
*/

Route::get('/internal/matters', [MattersToolController::class, 'index'])
    ->name('internal.matters');
