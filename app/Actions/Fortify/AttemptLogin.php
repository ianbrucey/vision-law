<?php

namespace App\Actions\Fortify;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Credential validation step of the login pipeline (C-01, C-03).
 *
 * On success the user is stashed on the request for the later pipeline
 * steps (2FA redirect, session login). On failure the attempt is counted
 * toward the brute-force lockout, an auth.login.failed audit event is
 * written, and the response is ALWAYS the generic invalid_credentials —
 * identical for unknown emails, wrong passwords, deactivated accounts, and
 * unverified accounts (no enumeration).
 */
class AttemptLogin
{
    public const REQUEST_USER_KEY = 'visionlaw.auth_user';

    public function __construct(
        protected LoginAttemptService $attempts,
    ) {}

    /**
     * @param  \Closure(Request): mixed  $next
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $email = LoginAttemptService::normalizeEmail((string) $request->input(Fortify::username()));
        $user = User::where('email', $email)->first();

        if ($user instanceof User && $this->credentialsValid($user, (string) $request->input('password'))) {
            $request->attributes->set(self::REQUEST_USER_KEY, $user);

            return $next($request);
        }

        $this->handleFailedAttempt($request, $email, $user);

        // Unreachable: handleFailedAttempt() always throws.
        return $next($request);
    }

    private function credentialsValid(User $user, string $password): bool
    {
        // 02-schema-delta.md: login is refused for deactivated accounts.
        if ($user->deactivated_at !== null) {
            return false;
        }

        if (! Hash::check($password, $user->password)) {
            return false;
        }

        // C-01: unverified accounts cannot log in. Reported with the same
        // generic error as bad credentials so verification state is not
        // enumerable.
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        return true;
    }

    /**
     * @throws HttpResponseException always
     */
    private function handleFailedAttempt(Request $request, string $email, ?User $user): void
    {
        $outcome = $this->attempts->recordFailure($email, $request->ip());

        // The payload carries no user identifier (no enumeration); the tenant
        // resolves from the matched user. Unknown emails have no user tenant —
        // the event is attributed to the system org (001-D13): never skipped,
        // never misattributed to a real org.
        $auditOrgId = $user instanceof User ? $user->org_id : Organization::system()->getKey();
        AuditLogger::log('auth.login.failed', $user, [
            'ip' => $request->ip(),
            'email_domain_digest' => LoginAttemptService::emailDomainDigest($email),
        ], explicitOrgId: $auditOrgId);

        if ($outcome->isLockedOut()) {
            AuditLogger::log('auth.login.locked_out', $user, ['ip' => $request->ip()], explicitOrgId: $auditOrgId);

            throw new HttpResponseException(
                response()->json([
                    'code' => 'locked_out',
                    'retry_after' => $outcome->retryAfterSeconds(),
                ], 429)
            );
        }

        throw new HttpResponseException(
            response()->json(['code' => 'invalid_credentials'], 422)
        );
    }
}
