<?php

namespace App\Providers;

use App\Http\Responses\FailedTwoFactorLoginResponse as AppFailedTwoFactorLoginResponse;
use App\Http\Responses\RegisterResponse as AppRegisterResponse;
use App\Listeners\EnforceSessionPolicies;
use App\Services\DocumentStore;
use App\Services\LocalDocumentStore;
use App\View\Components\Layouts\PublicLayout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * App-level event wiring (kept out of FortifyServiceProvider, which is
 * T-04's file).
 *
 * - Login → EnforceSessionPolicies: MFA-required-for-org-admins (spec
 *   005 005-D01: restricted setup-mode session, not logout+403),
 *   absolute-lifetime anchor, concurrent-session limit (C-06/C-07).
 * - RegisterResponse contract → App\Http\Responses\RegisterResponse:
 *   spec 003 T-05 (003-D02) verification toast on the browser registration
 *   redirect (Fortify's sanctioned hook; POST backend untouched).
 * - FailedTwoFactorLoginResponse contract →
 *   App\Http\Responses\FailedTwoFactorLoginResponse: one generic,
 *   non-enumerating failure message (spec 005 T-02 005-D05). The
 *   challenge GET route (005-D02) renders its Blade view directly, so no
 *   view-response binding is needed.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Spec 007 T-01 (007-D01): all file I/O goes through the
        // DocumentStore abstraction. LocalDocumentStore (local disk) is
        // the deployed driver; S3-compatible is designed, not deployed.
        $this->app->bind(DocumentStore::class, LocalDocumentStore::class);

        // Spec 003 T-05 (003-D02): the browser registration flow ends on
        // the sign-in page with a verification toast. Swapping the
        // RegisterResponse contract binding is Fortify's sanctioned hook
        // for this -- the POST backend (validation, user creation, no
        // auto-login) is untouched.
        $this->app->bind(RegisterResponseContract::class, AppRegisterResponse::class);

        // Spec 005 T-02 (005-D05): one generic, non-enumerating message for
        // every challenge failure (bad TOTP, bad recovery code, consumed
        // recovery code). Sanctioned contract hook; the POST backend is
        // untouched.
        $this->app->bind(FailedTwoFactorLoginResponseContract::class, AppFailedTwoFactorLoginResponse::class);
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
