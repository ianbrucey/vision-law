<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Final step of the login pipeline (C-03): establishes the session, rotates
 * the session ID, clears the brute-force counters, and audits the login.
 * (2FA logins complete in the challenge controller instead; see
 * AuthEventSubscriber::onValidTwoFactorCode.)
 */
class CompleteLogin
{
    public function __construct(
        protected StatefulGuard $guard,
        protected LoginAttemptService $attempts,
    ) {}

    /**
     * @param  \Closure(Request): mixed  $next
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        /** @var User $user */
        $user = $request->attributes->get(AttemptLogin::REQUEST_USER_KEY);

        $this->guard->login($user, $request->boolean('remember'));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // C-03: a successful login clears the failure counters.
        $this->attempts->clear(LoginAttemptService::normalizeEmail($user->email), $request->ip());

        $user->forceFill(['last_login_at' => now()])->save();

        AuditLogger::log('auth.login', $user, [
            'actor_id' => (string) $user->getKey(),
            'ip' => $request->ip(),
            'mfa_used' => false,
        ]);

        return $next($request);
    }
}
