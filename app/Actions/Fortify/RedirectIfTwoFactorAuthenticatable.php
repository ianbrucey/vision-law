<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's 2FA challenge redirect (C-06), but credential validation is
 * reused from AttemptLogin — the user is already verified, verification-
 * gated, and not locked out — instead of burning a second Argon2id check.
 *
 * Spec 008: Fortify's challenge decision keys off the TOTP secret only,
 * so a passkey-only user would otherwise sail through on a password
 * alone. The parent's decision (challenge for TOTP, fall-through
 * otherwise) is untouched; on fall-through, a user holding passkeys gets
 * Fortify's own challenge response — same session keys, same redirect —
 * and the challenge page offers the passkey ceremony (008-D06).
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

    /**
     * @param  mixed  $request
     * @return mixed
     */
    public function handle($request, $next)
    {
        $fellThrough = false;

        $response = parent::handle($request, function () use (&$fellThrough) {
            $fellThrough = true;

            return null;
        });

        if (! $fellThrough) {
            return $response;
        }

        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasPasskeys()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
