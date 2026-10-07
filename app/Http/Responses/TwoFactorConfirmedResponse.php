<?php

namespace App\Http\Responses;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\TwoFactorConfirmedResponse as FortifyTwoFactorConfirmedResponse;

/**
 * Spec 005 T-01 — what a successful two-factor.confirm returns.
 *
 * - Clears the setup-mode flag (005-D01): the restricted session becomes a
 *   full session immediately — no re-sign-in.
 * - Flashes the freshly-confirmed recovery codes for the once-display on
 *   the enrollment page (005-D03). Codes travel from this POST handler to
 *   the next GET only; the settings controller never re-reads them from
 *   the model.
 * - Web: redirects to the enrollment page (two-factor.settings), which
 *   renders the once-display. JSON: Fortify's 200, untouched (the headless
 *   API contract — existing tests assert assertOk()).
 *
 * The audit event mfa.enrolled is written by AuthEventSubscriber on
 * Fortify's TwoFactorAuthenticationConfirmed event; this class only shapes
 * the HTTP response. Swapping the contract binding is Fortify's sanctioned
 * hook — the confirm backend (code validation) is untouched.
 */
class TwoFactorConfirmedResponse extends FortifyTwoFactorConfirmedResponse
{
    public function toResponse($request)
    {
        $this->releaseSetupMode($request);

        $response = parent::toResponse($request);

        // Web: land on the enrollment page (once-display), not back().
        if ($response instanceof RedirectResponse) {
            return redirect()->route('two-factor.settings');
        }

        return $response;
    }

    private function releaseSetupMode(Request $request): void
    {
        $request->session()->forget(RestrictToTwoFactorSetup::SESSION_KEY);

        $user = $request->user();
        if ($user instanceof User) {
            $codes = $user->recoveryCodes();
            if ($codes !== []) {
                $request->session()->flash(RestrictToTwoFactorSetup::RECOVERY_CODES_FLASH_KEY, $codes);
            }
        }
    }
}
