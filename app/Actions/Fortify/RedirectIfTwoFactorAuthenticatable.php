<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's 2FA challenge redirect (C-06), but credential validation is
 * reused from AttemptLogin — the user is already verified, verification-
 * gated, and not locked out — instead of burning a second Argon2id check.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  mixed  $request
     * @return mixed
     */
    protected function validateCredentials($request)
    {
        return $request->attributes->get(AttemptLogin::REQUEST_USER_KEY);
    }
}
