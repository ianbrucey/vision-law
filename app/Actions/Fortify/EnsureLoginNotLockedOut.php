<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * First step of the login pipeline (C-03): rejects locked-out accounts with
 * 429 BEFORE credential validation, so lockouts fail fast without burning
 * Argon2id. Every lockout writes an audit event; unknown emails have no
 * tenant to attribute the event to, so the audit is skipped rather than
 * misattributed (spec gap recorded in the T-04 report).
 */
class EnsureLoginNotLockedOut
{
    public function __construct(
        protected LoginAttemptService $attempts,
    ) {}

    /**
     * @param  \Closure(Request): mixed  $next
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $email = LoginAttemptService::normalizeEmail((string) $request->input(Fortify::username()));
        $remaining = $this->attempts->lockoutRemaining($email, $request->ip());

        if ($remaining > 0) {
            $user = User::where('email', $email)->first();

            if ($user instanceof User) {
                AuditLogger::log('auth.login.locked_out', $user, ['ip' => $request->ip()]);
            }

            throw new HttpResponseException(
                response()->json(['code' => 'locked_out', 'retry_after' => $remaining], 429)
            );
        }

        return $next($request);
    }
}
