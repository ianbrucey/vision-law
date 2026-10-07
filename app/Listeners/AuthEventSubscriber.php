<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Audit + brute-force bookkeeping for Fortify's auth events (C-03, C-06).
 *
 * Audit rows are written ONLY through AuditLogger, and payloads carry no
 * privileged keys (the blocklist rejects password/secret/token/hash/
 * recovery substrings — see 001-D12 for the email_domain_digest naming).
 */
class AuthEventSubscriber
{
    public function __construct(
        protected LoginAttemptService $attempts,
    ) {}

    /**
     * @param  Dispatcher  $events
     */
    public function subscribe($events): void
    {
        $events->listen(
            ValidTwoFactorAuthenticationCodeProvided::class,
            [self::class, 'onValidTwoFactorCode']
        );
        $events->listen(
            TwoFactorAuthenticationFailed::class,
            [self::class, 'onTwoFactorFailed']
        );
        $events->listen(
            TwoFactorAuthenticationEnabled::class,
            [self::class, 'onMfaEnabled']
        );
        $events->listen(
            TwoFactorAuthenticationDisabled::class,
            [self::class, 'onMfaDisabled']
        );
        $events->listen(Logout::class, [self::class, 'onLogout']);
    }

    /**
     * 2FA challenge passed: this IS the login (the challenge controller logs
     * the user in directly). Clear the brute-force counters and audit with
     * mfa_used = true.
     */
    public function onValidTwoFactorCode(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        /** @var User $user */
        $user = $event->user;
        $ip = request()->ip();

        $this->attempts->clear(LoginAttemptService::normalizeEmail($user->email), $ip);

        $user->forceFill(['last_login_at' => now()])->save();

        AuditLogger::log('auth.login', $user, [
            'actor_id' => (string) $user->getKey(),
            'ip' => $ip,
            'mfa_used' => true,
        ]);
    }

    /**
     * 2FA challenge failed: count it toward the brute-force lockout — TOTP
     * codes must not be guessable without limit.
     */
    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        /** @var User $user */
        $user = $event->user;
        $email = LoginAttemptService::normalizeEmail($user->email);
        $ip = request()->ip();

        $outcome = $this->attempts->recordFailure($email, $ip);

        AuditLogger::log('auth.login.failed', $user, [
            'ip' => $ip,
            'email_domain_digest' => LoginAttemptService::emailDomainDigest($email),
        ]);

        if ($outcome->isLockedOut()) {
            AuditLogger::log('auth.login.locked_out', $user, ['ip' => $ip]);
        }
    }

    public function onMfaEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        /** @var User $user */
        $user = $event->user;

        AuditLogger::log('auth.mfa.enabled', $user, ['actor_id' => (string) $user->getKey()]);
    }

    public function onMfaDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        /** @var User $user */
        $user = $event->user;

        AuditLogger::log('auth.mfa.disabled', $user, ['actor_id' => (string) $user->getKey()]);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            AuditLogger::log('auth.logout', $event->user, [
                'actor_id' => (string) $event->user->getKey(),
            ]);
        }
    }
}
