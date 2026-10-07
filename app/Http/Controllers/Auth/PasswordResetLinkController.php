<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * C-04: the reset-link response is identical whether or not the email
 * belongs to an account — no account enumeration. A reset link is only ever
 * sent for an existing user.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $user = Password::broker((string) config('fortify.passwords'))
            ->getUser($request->only(Fortify::email()));

        if ($user) {
            $this->broker()->sendResetLink($request->only(Fortify::email()));
        }

        return app(SuccessfulPasswordResetLinkRequestResponse::class, [
            'status' => Password::RESET_LINK_SENT,
        ]);
    }
}
