<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Login-completion policies (C-06, C-07), enforced on the Login event so both
 * login paths are covered: the password pipeline (CompleteLogin) and the 2FA
 * challenge (TwoFactorAuthenticatedSessionController).
 *
 * 1. MFA required for org admins: an org_admin without enrolled 2FA is
 *    logged straight back out and denied with 403 {code: "mfa_required"}
 *    (JSON, no Blade per 001-D06). The denial is audited as
 *    auth.login.failed — no new event name invented.
 * 2. Absolute-lifetime anchor: visionlaw.login_at is stamped into the
 *    session for EnsureSessionLifetime to enforce.
 * 3. Concurrent-session limit (default 5): the oldest sessions beyond the
 *    limit are kicked. The current session row is written at request end,
 *    so (limit - 1) pre-existing rows are kept.
 */
class EnforceSessionPolicies
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        if ($user->hasRole('org_admin') && ! $user->hasEnabledTwoFactorAuthentication()) {
            // Never leave an MFA-less org admin authenticated: log out first,
            // then deny. (The Logout event audits auth.logout via the
            // existing subscriber.)
            Auth::guard($event->guard)->logout();

            AuditLogger::log('auth.login.failed', $user, [
                'ip' => request()->ip(),
                'email_domain_digest' => LoginAttemptService::emailDomainDigest(
                    LoginAttemptService::normalizeEmail((string) $user->email)
                ),
            ]);

            throw new HttpResponseException(
                response()->json(['code' => 'mfa_required'], 403)
            );
        }

        $request = request();

        if ($request->hasSession()) {
            $request->session()->put('visionlaw.login_at', now()->timestamp);
        }

        $this->enforceConcurrentSessionLimit($user);
    }

    private function enforceConcurrentSessionLimit(User $user): void
    {
        $limit = (int) config('visionlaw.session_limit', 5);

        if ($limit < 1) {
            return;
        }

        $existing = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->pluck('id');

        if ($existing->count() >= $limit) {
            DB::table('sessions')
                ->whereIn('id', $existing->slice($limit - 1)->all())
                ->delete();
        }
    }
}
