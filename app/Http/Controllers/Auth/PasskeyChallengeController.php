<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginAttemptService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laragear\WebAuthn\Assertion\Creator\AssertionCreation;
use Laragear\WebAuthn\Assertion\Creator\AssertionCreator;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\Enums\UserVerification;
use Laragear\WebAuthn\Exceptions\AssertionException;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Spec 008 — the passkey half of the login challenge (03-contract.md).
 *
 * Completion deliberately mirrors Fortify's TOTP challenge store
 * (008-D06): same challenged-session keys, same lockout accounting via
 * LoginAttemptService, same completion side effects — achieved by firing
 * Fortify's own ValidTwoFactorAuthenticationCodeProvided /
 * TwoFactorAuthenticationFailed events, whose subscriber
 * (AuthEventSubscriber) clears counters, stamps last_login_at, and
 * audits auth.login with mfa_used = true. On top of that this controller
 * writes the spec's mfa.passkey.challenge.* audit events.
 *
 * Every failure is the same surface: 422 {"code": "invalid_passkey"}.
 * No exception class, message, or ceremony detail reaches the client
 * (C-05) or the audit payload beyond a coarse reason (C-11).
 */
class PasskeyChallengeController extends Controller
{
    public function __construct(
        protected StatefulGuard $guard,
        protected LoginAttemptService $attempts,
    ) {}

    /**
     * POST two-factor-challenge/passkey/options — assertion options
     * scoped to the challenged user's credentials, UV required (008-D07).
     */
    public function options(Request $request): Responsable
    {
        $user = $this->challengedUser($request);

        $creation = new AssertionCreation(
            user: $user,
            userVerification: UserVerification::Required,
        );

        $creation = app(AssertionCreator::class)->send($creation)->thenReturn();

        return $creation->json;
    }

    /**
     * POST two-factor-challenge/passkey — validate the assertion and, on
     * success, complete the login exactly as the TOTP store does.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->challengedUser($request);

        $remaining = $this->attempts->lockoutRemaining((string) $user->email, $request->ip());
        if ($remaining > 0) {
            throw new HttpResponseException(
                response()->json(['code' => 'locked_out', 'retry_after' => $remaining], 429)
            );
        }

        $validation = AssertionValidation::fromRequest($request);
        $validation->user = $user;

        try {
            app(AssertionValidator::class)->send($validation)->thenReturn();
        } catch (AssertionException|Throwable $e) {
            // The Fortify failed event feeds the same lockout + audit
            // bookkeeping a bad TOTP code gets (AuthEventSubscriber).
            event(new TwoFactorAuthenticationFailed($user));

            AuditLogger::log('mfa.passkey.challenge.failed', $user, [
                'actor_id' => (string) $user->getKey(),
                'reason' => $this->coarseReason($e),
                'ip' => $request->ip(),
            ]);

            throw new HttpResponseException(
                response()->json(['code' => 'invalid_passkey'], 422)
            );
        }

        AuditLogger::log('mfa.passkey.challenge.succeeded', $user, [
            'actor_id' => (string) $user->getKey(),
            'credential_label' => (string) ($validation->credential?->alias ?? 'Passkey'),
            'ip' => $request->ip(),
        ]);

        // Fortify's completion sequence (TwoFactorAuthenticatedSession-
        // Controller::store): event, guard login, session regeneration.
        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->forget(['login.id', 'login.remember']);

        $this->guard->login($user, $remember);
        $request->session()->regenerate();

        return response()->json([
            'redirect' => redirect()->intended(Fortify::redirects('login'))->getTargetUrl(),
        ]);
    }

    /**
     * The challenged user from Fortify's session keys, or the contract's
     * generic 422 when there is no live challenged session.
     */
    private function challengedUser(Request $request): User
    {
        $id = $request->session()->get('login.id');
        $user = is_string($id) ? User::find($id) : null;

        if (! $user instanceof User) {
            throw new HttpResponseException(
                response()->json(['code' => 'invalid_challenge'], 422)
            );
        }

        return $user;
    }

    /**
     * Coarse failure taxonomy for the audit payload (03-contract.md):
     * enough to operate on, never enough to probe with. Laragear's
     * exception messages are matched loosely; anything unrecognized is
     * assertion_rejected, and unparseable input is malformed.
     */
    private function coarseReason(Throwable $e): string
    {
        if (! $e instanceof AssertionException) {
            return 'malformed';
        }

        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'challenge') => 'replay',
            str_contains($message, 'signature') => 'bad_signature',
            str_contains($message, 'origin'),
            str_contains($message, 'relying party') => 'wrong_origin',
            str_contains($message, 'credential') => 'unknown_credential',
            default => 'assertion_rejected',
        };
    }
}
