<?php

namespace App\Providers;

use App\Actions\Fortify\AttemptLogin;
use App\Actions\Fortify\CompleteLogin;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnableTwoFactorAuthentication as AppEnableTwoFactorAuthentication;
use App\Actions\Fortify\EnsureLoginNotLockedOut;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Listeners\AuthEventSubscriber;
use App\Listeners\RevokeSessionsOnPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * Ticket 4: wires Fortify's headless auth backend (001-D01).
 *
 * - Fortify action classes (registration, password update/reset).
 * - Custom login pipeline: lockout check → credential validation (with the
 *   email-verification gate and failure counting) → 2FA redirect → login
 *   completion. Replaces Fortify's default pipeline (C-01, C-03).
 * - 10 backup codes on 2FA enrollment instead of Fortify's 8 (C-06).
 * - Auth event listeners: verification mail, session revocation on password
 *   reset, audit + brute-force bookkeeping (C-01, C-03, C-04, C-06).
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            FortifyEnableTwoFactorAuthentication::class,
            AppEnableTwoFactorAuthentication::class
        );
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateThrough(fn (Request $request) => [
            EnsureLoginNotLockedOut::class,
            AttemptLogin::class,
            RedirectIfTwoFactorAuthenticatable::class,
            CompleteLogin::class,
        ]);

        // C-01: verification mail on registration.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        // C-04: password reset revokes ALL sessions and is audited.
        Event::listen(PasswordReset::class, RevokeSessionsOnPasswordReset::class);

        // C-03/C-06: audit + brute-force bookkeeping for Fortify auth events.
        Event::subscribe(AuthEventSubscriber::class);
    }
}
