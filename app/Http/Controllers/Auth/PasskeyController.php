<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * Spec 008 — passkey management for an authenticated user (03-contract.md).
 *
 * The ceremonies themselves are Laragear's pipelines (008-D01): options via
 * AttestationRequest, validation + persistence via AttestedRequest. This
 * controller owns only what the package does not:
 *
 * - the credential label (008-D10),
 * - audit events (mfa.passkey.*) written through AuditLogger,
 * - first-factor recovery-code issuance (008-D02),
 * - clearing the setup-mode flag when registration satisfies MFA (C-03),
 * - revocation scoped to the owner's own credentials (008-D08).
 */
class PasskeyController extends Controller
{
    /**
     * POST user/passkeys/register/options — attestation options JSON.
     * User verification is required (008-D07): the factor must prove the
     * user (biometric/PIN), not mere device presence.
     */
    public function registerOptions(AttestationRequest $request): Responsable
    {
        return $request->secureRegistration()->toCreate();
    }

    /**
     * POST user/passkeys — validate the ceremony and store the credential.
     *
     * AttestedRequest has already validated the attestation (challenge,
     * RP-ID hash, origin, UV, format) by the time this runs; ->save()
     * persists the credential for the authenticated user.
     */
    public function store(AttestedRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'alias' => ['nullable', 'string', 'max:40'],
        ]);

        $label = $this->sanitizeLabel($validated['alias'] ?? null);

        $request->save(['alias' => $label]);

        AuditLogger::log('mfa.passkey.registered', $user, [
            'actor_id' => (string) $user->getKey(),
            'credential_label' => $label,
        ]);

        $this->issueRecoveryCodesIfFirstFactor($request, $user, $label);

        // C-03: if this registration satisfies the MFA requirement, the
        // setup-mode restriction ends immediately — the same release the
        // TOTP confirm response performs (TwoFactorConfirmedResponse).
        if ($request->session()->get(RestrictToTwoFactorSetup::SESSION_KEY, false)
            && ($user->hasEnabledTwoFactorAuthentication() || $user->hasPasskeys())) {
            $request->session()->forget(RestrictToTwoFactorSetup::SESSION_KEY);
        }

        return response()->json(['redirect' => route('two-factor.settings')]);
    }

    /**
     * DELETE user/passkeys/{credential} — revoke one of the user's own
     * passkeys. The password.confirm middleware on the route is the gate
     * (C-06), mirroring the 2FA disable flow. Resolution happens inside
     * the owner's relation: another user's credential id is a 404.
     */
    public function destroy(Request $request, string $credential): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $passkey = $user->webAuthnCredentials()->whereKey($credential)->firstOrFail();

        $label = (string) ($passkey->alias ?? 'Passkey');
        $passkey->delete();

        AuditLogger::log('mfa.passkey.revoked', $user, [
            'actor_id' => (string) $user->getKey(),
            'credential_label' => $label,
        ]);

        return redirect()
            ->route('two-factor.settings')
            ->with('toast', [
                'message' => "Passkey '{$label}' revoked. It can no longer be used to sign in.",
                'tone' => 'ok',
            ]);
    }

    /**
     * 008-D02: a passkey-only user gets the same break-glass as a TOTP
     * user — 8 single-use recovery codes in Fortify's exact storage shape
     * — issued when the first passkey lands and no codes exist yet. The
     * codes are flashed for the enrollment page's once-display and never
     * re-read from the model afterwards (005-D03).
     */
    private function issueRecoveryCodesIfFirstFactor(AttestedRequest $request, User $user, string $label): void
    {
        if ($user->two_factor_recovery_codes !== null) {
            return;
        }

        $codes = Collection::times(8, function () {
            return RecoveryCode::generate();
        })->all();

        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes)),
        ])->save();

        $request->session()->flash(
            RestrictToTwoFactorSetup::RECOVERY_CODES_FLASH_KEY,
            $codes
        );

        AuditLogger::log('mfa.passkey.recovery_codes.issued', $user, [
            'actor_id' => (string) $user->getKey(),
            'credential_label' => $label,
        ]);
    }

    /**
     * 008-D10: labels are user-generated content — trimmed, stripped of
     * control characters, defaulted when blank. Rendered escaped by Blade;
     * audited as the only credential datum.
     */
    private function sanitizeLabel(?string $alias): string
    {
        $label = trim((string) preg_replace('/\p{C}/u', '', (string) $alias));

        return $label !== '' ? $label : 'Passkey · '.now()->format('M j, Y');
    }
}
