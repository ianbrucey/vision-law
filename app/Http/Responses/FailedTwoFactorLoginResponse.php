<?php

namespace App\Http\Responses;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;

/**
 * Spec 005 T-02 (005-D05): challenge failures are one generic,
 * non-enumerating message for a bad TOTP code, a bad recovery code, and
 * a consumed recovery code — matching the invalid_credentials tone of
 * the login pipeline. The error key is always `code` regardless of which
 * form was submitted, so nothing about the failure is probeable.
 *
 * Swapping the contract binding is Fortify's sanctioned hook; the POST
 * backend (validation, code verification, recovery-code consumption) is
 * untouched. Bound in AppServiceProvider.
 */
class FailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    public const MESSAGE = "That code didn't work. Try the current code from your app.";

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            throw ValidationException::withMessages(['code' => [self::MESSAGE]]);
        }

        throw new HttpResponseException(
            redirect()->route('two-factor.login')->withErrors(['code' => self::MESSAGE])
        );
    }
}
