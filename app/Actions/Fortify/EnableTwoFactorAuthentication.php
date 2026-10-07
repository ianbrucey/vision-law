<?php

namespace App\Actions\Fortify;

use Illuminate\Support\Collection;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Fortify's 2FA enrollment, generating 10 single-use backup codes (C-06)
 * instead of Fortify's default 8. Bound over the Fortify action in
 * FortifyServiceProvider.
 */
class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     */
    public function __invoke($user, $force = false): void
    {
        if (empty($user->two_factor_secret) || $force === true) {
            $secretLength = (int) config('fortify-options.two-factor-authentication.secret-length', 16);

            // The contract types $this->provider without the length
            // parameter; the bound Fortify provider accepts it.
            /** @var TwoFactorAuthenticationProvider $provider */
            $provider = $this->provider;

            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($provider->generateSecretKey($secretLength)),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(10, function () {
                    return RecoveryCode::generate();
                })->all())),
            ])->save();

            TwoFactorAuthenticationEnabled::dispatch($user);
        }
    }
}
