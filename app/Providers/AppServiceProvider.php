<?php

namespace App\Providers;

use App\Http\Responses\RegisterResponse as AppRegisterResponse;
use App\Listeners\EnforceSessionPolicies;
use App\View\Components\Layouts\PublicLayout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * App-level event wiring (kept out of FortifyServiceProvider, which is
 * T-04's file).
 *
 * - Login → EnforceSessionPolicies: MFA-required-for-org-admins (403
 *   mfa_required), absolute-lifetime anchor, concurrent-session limit
 *   (C-06/C-07).
 * - RegisterResponse contract → App\Http\Responses\RegisterResponse:
 *   spec 003 T-05 (003-D02) verification toast on the browser registration
 *   redirect (Fortify's sanctioned hook; POST backend untouched).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Spec 003 T-05 (003-D02): the browser registration flow ends on
        // the sign-in page with a verification toast. Swapping the
        // RegisterResponse contract binding is Fortify's sanctioned hook
        // for this -- the POST backend (validation, user creation, no
        // auto-login) is untouched.
        $this->app->bind(RegisterResponseContract::class, AppRegisterResponse::class);
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
