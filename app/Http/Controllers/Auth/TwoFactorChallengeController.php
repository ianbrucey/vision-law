<?php

namespace App\Http\Controllers\Auth;

use App\Services\LoginAttemptService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController as FortifyTwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Fortify's 2FA challenge with the brute-force lockout applied (C-03): a
 * locked-out account cannot complete the challenge either.
 */
class TwoFactorChallengeController extends FortifyTwoFactorAuthenticatedSessionController
{
    public function store(TwoFactorLoginRequest $request)
    {
        if (! $request->hasChallengedUser()) {
            throw new HttpResponseException(
                response()->json(['code' => 'invalid_challenge'], 422)
            );
        }

        $user = $request->challengedUser();

        $remaining = app(LoginAttemptService::class)
            ->lockoutRemaining((string) $user->email, $request->ip());

        if ($remaining > 0) {
            throw new HttpResponseException(
                response()->json(['code' => 'locked_out', 'retry_after' => $remaining], 429)
            );
        }

        return parent::store($request);
    }
}
