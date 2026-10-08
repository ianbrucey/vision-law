<?php

namespace App\Listeners;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Login-completion policies (C-06, C-07), enforced on the Login event so both
 * login paths are covered: the password pipeline (CompleteLogin) and the 2FA
 * challenge (TwoFactorAuthenticatedSessionController).
 *
 * 1. MFA required for org admins: an org_admin without enrolled 2FA gets a
 *    restricted "setup-mode" session (spec 005, 005-D01) instead of the old
 *    logout+403. The session flag visionlaw.2fa_setup_required limits the
 *    session to the enrollment surface (RestrictToTwoFactorSetup) until
 *    two-factor.confirm clears it. The policy itself is unchanged — admins
 *    must enroll — only the denial shape became shippable. The issuance is
 *    audited as auth.login.2fa_enrollment_required. Spec 008: a confirmed
 *    passkey satisfies the same requirement (hasPasskeys()), since the
 *    enrollment surface offers both paths.
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

        if ($user->hasRole('org_admin') && ! $user->hasEnabledTwoFactorAuthentication() && ! $user->hasPasskeys()) {
            $request = request();

            if ($request->hasSession()) {
                $session = $request->session();

                // 005-D01: restricted setup-mode session (replaces logout+403
                // for this case only). The flag lives in the session only —
                // never persisted to the user row.
                $session->put(RestrictToTwoFactorSetup::SESSION_KEY, true);

                // The password was proven seconds ago in this very request,
                // so the enrollment POSTs (behind Fortify's password.confirm)
                // work without a second password prompt. The setup-mode
                // allowlist keeps the session restricted to the enrollment
                // surface regardless.
                $session->put('auth.password_confirmed_at', now()->timestamp);

                // Rotate the session id on the privilege change, mirroring
                // CompleteLogin's normal-path rotation.
                $session->regenerate();
            }

            AuditLogger::log('auth.login.2fa_enrollment_required', $user, [
                'ip' => $request->ip(),
                'email_domain_digest' => LoginAttemptService::emailDomainDigest(
                    LoginAttemptService::normalizeEmail((string) $user->email)
                ),
            ]);

            throw new HttpResponseException(
                redirect()->route('two-factor.settings')
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
