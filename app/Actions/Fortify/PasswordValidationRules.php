<?php

namespace App\Actions\Fortify;

use App\Rules\NotBreachedPassword;
use Illuminate\Validation\Rules\Password;

/**
 * Shared password policy (C-02): minimum 12 characters plus the fail-closed
 * breached-password check (001-D07).
 */
trait PasswordValidationRules
{
    /**
     * @return list<mixed>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(12), new NotBreachedPassword];
    }
}
