<?php

namespace App\Providers;

use App\Listeners\EnforceSessionPolicies;
use App\View\Components\Layouts\PublicLayout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Blade;
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

        // <x-layouts.public> alias for the spec 003 public shell. The class is
        // named PublicLayout because Public is a PHP reserved word.
        Blade::component(PublicLayout::class, 'layouts.public');
    }
}
