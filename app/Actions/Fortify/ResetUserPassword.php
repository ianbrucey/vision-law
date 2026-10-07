<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Password reset completion (C-04): enforces the same password policy as
 * registration (min 12 chars, fail-closed breach check). Session revocation
 * happens in the PasswordReset event listener.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        /** @var array{password: string} $validated */
        $validated = Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // The 'hashed' cast hashes this on set.
        $user->forceFill(['password' => $validated['password']])->save();
    }
}
