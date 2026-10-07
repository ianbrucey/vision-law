<?php

namespace App\Rules;

use App\Exceptions\BreachedPasswordCheckUnavailableException;
use App\Services\BreachedPasswordChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects passwords found in breach corpora (C-02). Fails closed per 001-D07:
 * when the k-anonymity API is unreachable, validation fails with a retryable
 * message instead of letting the password through.
 */
class NotBreachedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $breached = app(BreachedPasswordChecker::class)->isBreached($value);
        } catch (BreachedPasswordCheckUnavailableException) {
            $fail('The password safety check is temporarily unavailable. Please try again.');

            return;
        }

        if ($breached) {
            $fail('This password has appeared in a data breach. Please choose a different password.');
        }
    }
}
