<?php

namespace App\Http\Responses;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Http\Responses\RecoveryCodesGeneratedResponse as FortifyRecoveryCodesGeneratedResponse;

/**
 * Spec 005 T-01 — what a successful recovery-code regeneration returns.
 *
 * Same once-display mechanism as the confirm response (005-D03): the new
 * codes are flashed from this POST handler to the next enrollment-page GET
 * only; the settings controller never re-reads them from the model.
 * Regenerating invalidates the old set immediately (Fortify).
 *
 * Web: redirects to the enrollment page (two-factor.settings), which
 * renders the once-display. JSON: Fortify's 200, untouched.
 */
class RecoveryCodesGeneratedResponse extends FortifyRecoveryCodesGeneratedResponse
{
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User) {
            $codes = $user->recoveryCodes();
            if ($codes !== []) {
                $request->session()->flash(RestrictToTwoFactorSetup::RECOVERY_CODES_FLASH_KEY, $codes);
            }
        }

        $response = parent::toResponse($request);

        // Web: land on the enrollment page (once-display), not back().
        if ($response instanceof RedirectResponse) {
            return redirect()->route('two-factor.settings');
        }

        return $response;
    }
}
