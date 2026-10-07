<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;

/**
 * Fortify's registration WITHOUT the auto-login: C-01 requires that
 * unverified accounts cannot log in, so a fresh registration ends in the
 * pending-verification state (the verification mail goes out via the
 * Registered event) instead of an authenticated session.
 */
class RegisteredUserController extends FortifyRegisteredUserController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        event(new Registered($creator->create($request->all())));

        return app(RegisterResponse::class);
    }
}
