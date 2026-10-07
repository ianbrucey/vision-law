<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Brute-force protection for the login flow (C-03).
 *
 * Counting is cache-backed so no migration is needed: failures are tracked
 * per account (email + IP) AND per IP. Five account failures trigger a
 * 15-minute lockout; every subsequent lockout cycle doubles the duration
 * (exponential backoff). A distributed attack trips the per-IP lockout.
 *
 * The lockout check runs BEFORE credential validation so locked-out accounts
 * fail fast without burning Argon2id or leaking timing differences.
 *
 * Cache keys carry SHA-1 digests, never raw emails or IPs.
 */
class LoginAttemptService
{
    public const MAX_ATTEMPTS = 5;

    public const BASE_LOCKOUT_SECONDS = 900;

    public const IP_MAX_ATTEMPTS = 30;

    public const IP_LOCKOUT_SECONDS = 900;

    /**
     * Record a failed attempt. Returns whether this failure triggered (or
     * extended into) a lockout, with the retry-after in seconds.
     */
    public function recordFailure(string $email, string $ip): LoginAttemptOutcome
    {
        $emailKey = self::normalizeEmail($email);

        $accountFailures = $this->bump($this->accountFailuresKey($emailKey, $ip), self::BASE_LOCKOUT_SECONDS);
        $ipFailures = $this->bump($this->ipFailuresKey($ip), self::BASE_LOCKOUT_SECONDS);

        if ($accountFailures >= self::MAX_ATTEMPTS) {
            return $this->triggerAccountLockout($emailKey, $ip);
        }

        if ($ipFailures >= self::IP_MAX_ATTEMPTS) {
            $this->putWithTtl($this->ipLockoutKey($ip), true, self::IP_LOCKOUT_SECONDS);

            return LoginAttemptOutcome::lockedOut(self::IP_LOCKOUT_SECONDS);
        }

        return LoginAttemptOutcome::failed();
    }

    /**
     * Seconds until the lockout lifts for this account+IP or IP, 0 when not
     * locked. The expiry timestamp is stored as the value so the remaining
     * time survives clock travel in tests.
     */
    public function lockoutRemaining(string $email, string $ip): int
    {
        $emailKey = self::normalizeEmail($email);

        foreach ([$this->accountLockoutKey($emailKey, $ip), $this->ipLockoutKey($ip)] as $key) {
            $expiresAt = Cache::get($key);

            if (is_int($expiresAt)) {
                $remaining = $expiresAt - now()->getTimestamp();

                if ($remaining > 0) {
                    return $remaining;
                }

                Cache::forget($key);
            }
        }

        return 0;
    }

    public function isLockedOut(string $email, string $ip): bool
    {
        return $this->lockoutRemaining($email, $ip) > 0;
    }

    /**
     * Called on successful login (C-03): clears the account's failure
     * counters and lockout state. IP-level counters are attack signal and
     * are left alone.
     */
    public function clear(string $email, string $ip): void
    {
        $emailKey = self::normalizeEmail($email);

        Cache::forget($this->accountFailuresKey($emailKey, $ip));
        Cache::forget($this->accountLockoutKey($emailKey, $ip));
        Cache::forget($this->accountLockoutCountKey($emailKey, $ip));
    }

    /**
     * Normalize an email for throttle keys and lookups.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Privacy-preserving domain identifier for the auth.login.failed audit
     * payload (001-D12): HMAC-SHA256 of the lowercased domain under the app
     * key. Reveals neither the email nor whether the account exists, and the
     * key name contains no 'hash' substring (AuditLogger blocklist).
     */
    public static function emailDomainDigest(string $email): string
    {
        $normalized = self::normalizeEmail($email);
        $at = strrpos($normalized, '@');
        $domain = $at === false ? '' : substr($normalized, $at + 1);

        return hash_hmac('sha256', $domain, (string) config('app.key'));
    }

    private function triggerAccountLockout(string $emailKey, string $ip): LoginAttemptOutcome
    {
        $countKey = $this->accountLockoutCountKey($emailKey, $ip);
        $cycles = (int) Cache::get($countKey, 0) + 1;
        Cache::put($countKey, $cycles, 86400);

        // Exponential backoff: 15 min, 30 min, 60 min, ...
        $duration = self::BASE_LOCKOUT_SECONDS * (2 ** ($cycles - 1));

        $this->putWithTtl($this->accountLockoutKey($emailKey, $ip), true, $duration);
        Cache::forget($this->accountFailuresKey($emailKey, $ip));

        return LoginAttemptOutcome::lockedOut($duration);
    }

    private function bump(string $key, int $ttlSeconds): int
    {
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, $ttlSeconds);

        return $count;
    }

    /**
     * Store a lockout flag whose VALUE is the expiry timestamp, so
     * lockoutRemaining() can report exact seconds.
     */
    private function putWithTtl(string $key, bool $value, int $ttlSeconds): void
    {
        Cache::put($key, $value ? now()->addSeconds($ttlSeconds)->getTimestamp() : 0, $ttlSeconds);
    }

    private function accountFailuresKey(string $emailKey, string $ip): string
    {
        return 'vl:login:fail:acct:'.sha1($emailKey.'|'.$ip);
    }

    private function accountLockoutKey(string $emailKey, string $ip): string
    {
        return 'vl:login:lock:acct:'.sha1($emailKey.'|'.$ip);
    }

    private function accountLockoutCountKey(string $emailKey, string $ip): string
    {
        return 'vl:login:lockcount:acct:'.sha1($emailKey.'|'.$ip);
    }

    private function ipFailuresKey(string $ip): string
    {
        return 'vl:login:fail:ip:'.sha1($ip);
    }

    private function ipLockoutKey(string $ip): string
    {
        return 'vl:login:lock:ip:'.sha1($ip);
    }
}
