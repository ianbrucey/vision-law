<?php

namespace App\Providers;

use App\Listeners\EnforceSessionPolicies;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * App-level event wiring (kept out of FortifyServiceProvider, which is
 * T-04's file).
 *
 * - Login → EnforceSessionPolicies: MFA-required-for-org-admins (403
 *   mfa_required), absolute-lifetime anchor, concurrent-session limit
 *   (C-06/C-07).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, EnforceSessionPolicies::class);
    }
}
