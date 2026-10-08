<?php

use App\Http\Controllers\ShareLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hardened external share-link endpoint (007 T-08, DOC-25)
|--------------------------------------------------------------------------
|
| This file is deliberately separate from routes/web.php: the anonymous
| /s/{token} surface is minimal, carries no session-auth middleware, and
| is rate-limited per IP. Tokens are 48 URL-safe characters; anything
| else 404s at the route constraint, before any database access.
|
| Loaded from the T-08 section of routes/web.php (the require keeps the
| registration next to the other 007 document routes while the file —
| and its middleware group — stays physically separate per 03-contract.md).
|
*/

Route::middleware(['web', 'throttle:60,1'])->prefix('s')->group(function (): void {
    Route::get('{token}', [ShareLinkController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name('shares.show');
    Route::post('{token}', [ShareLinkController::class, 'unlock'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name('shares.unlock');
    Route::post('{token}/download', [ShareLinkController::class, 'download'])
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name('shares.download');
});
