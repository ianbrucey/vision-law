<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\RegisterViewResponse;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;
use Laravel\Fortify\Http\Responses\SimpleViewResponse;

/**
 * Fortify's registration WITHOUT the auto-login: C-01 requires that
 * unverified accounts cannot log in, so a fresh registration ends in the
 * pending-verification state (the verification mail goes out via the
 * Registered event) instead of an authenticated session.
 */
class RegisteredUserController extends FortifyRegisteredUserController
{
    /**
     * Spec 003 T-05: the registration view.
     *
     * The parent declares create(Request): RegisterViewResponse, so the
     * view is returned through Fortify's own SimpleViewResponse (the same
     * class Fortify::registerView() builds). The optional invitation_token
     * is read from the query string by the view itself and rendered as a
     * hidden field, never displayed (03-contract.md Requests). No user
     * data is passed to the view (C-04).
     */
    public function create(Request $request): RegisterViewResponse
    {
        return new SimpleViewResponse('auth.register');
    }

    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        event(new Registered($creator->create($request->all())));

        return app(RegisterResponse::class);
    }
}
