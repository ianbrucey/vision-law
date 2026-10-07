<?php

namespace App\Services;

/**
 * The outcome of recording a failed login attempt (C-03).
 */
final class LoginAttemptOutcome
{
    private function __construct(
        private readonly bool $lockedOut,
        private readonly int $retryAfterSeconds,
    ) {}

    public static function failed(): self
    {
        return new self(false, 0);
    }

    public static function lockedOut(int $retryAfterSeconds): self
    {
        return new self(true, $retryAfterSeconds);
    }

    public function isLockedOut(): bool
    {
        return $this->lockedOut;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
