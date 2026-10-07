<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Http\Responses\RegisterResponse as FortifyRegisterResponse;

/**
 * Spec 003 T-05 (003-D02): the browser registration flow ends on the
 * sign-in page with a verification toast (rendered by <x-ui.toast>, which
 * reads session('toast')).
 *
 * The JSON/headless path is Fortify's untouched 201 — the flash applies
 * only to the redirect (view) response. The POST backend itself —
 * validation, user creation, no auto-login — is not modified here.
 */
class RegisterResponse extends FortifyRegisterResponse
{
    public function toResponse($request)
    {
        $response = parent::toResponse($request);

        if ($response instanceof RedirectResponse) {
            $response->with('toast', [
                'tone' => 'ok',
                'message' => 'Check your email to verify your account, then sign in.',
            ]);
        }

        return $response;
    }
}
