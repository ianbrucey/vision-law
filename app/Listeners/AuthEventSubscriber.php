<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
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
            TwoFactorAuthenticationConfirmed::class,
            [self::class, 'onMfaConfirmed']
        );
        $events->listen(
            TwoFactorAuthenticationDisabled::class,
            [self::class, 'onMfaDisabled']
        );
        $events->listen(
            RecoveryCodesGenerated::class,
            [self::class, 'onRecoveryCodesRegenerated']
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

        // Spec 005 (03-contract.md): the 005-contract name for a rejected
        // challenge code. Generic -- no code detail is logged.
        AuditLogger::log('mfa.challenge.failed', $user, ['actor_id' => (string) $user->getKey()]);

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

    /**
     * Spec 005 (03-contract.md): two-factor.confirm succeeded - the
     * enrollment is complete. Distinct from auth.mfa.enabled, which fires
     * at enable time (unconfirmed secret).
     */
    public function onMfaConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        /** @var User $user */
        $user = $event->user;

        AuditLogger::log('mfa.enrolled', $user, ['actor_id' => (string) $user->getKey()]);
    }

    public function onMfaDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        /** @var User $user */
        $user = $event->user;

        AuditLogger::log('auth.mfa.disabled', $user, ['actor_id' => (string) $user->getKey()]);

        // Spec 005 (03-contract.md): the 005-contract name for a completed
        // disable after password confirmation.
        AuditLogger::log('mfa.disabled', $user, ['actor_id' => (string) $user->getKey()]);
    }

    /**
     * Spec 005 (03-contract.md): recovery codes regenerated. The
     * RecoveryCodesGenerated event fires only from the regenerate endpoint
     * (GenerateNewRecoveryCodes is not invoked at enable/confirm time), so
     * this maps exactly to "regeneration POST".
     */
    public function onRecoveryCodesRegenerated(RecoveryCodesGenerated $event): void
    {
        /** @var User $user */
        $user = $event->user;

        AuditLogger::log('mfa.recovery_codes.regenerated', $user, ['actor_id' => (string) $user->getKey()]);
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
