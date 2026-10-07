<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\RestrictToTwoFactorSetup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Spec 005 T-01 — 2FA enrollment page (005-D02).
 *
 * GET user/two-factor (name two-factor.settings), authenticated. Four render
 * states, per 05-ui.md:
 *  - recovery codes once-display — flashed by the confirm/regenerate POST
 *    handler into the session; the view NEVER re-reads codes from the model,
 *    so a refresh or a later visit renders status only;
 *  - setup mode — the restricted session (brass banner, "Confirm and continue");
 *  - fresh — any authenticated user without confirmed 2FA;
 *  - active — confirmed 2FA management (regenerate / disable).
 */
class TwoFactorSettingsController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        // Once-display: codes flashed by the confirm/regenerate POST handler.
        // Session flash survives exactly one request. Never
        // $user->recoveryCodes() here — that would re-display on every visit.
        /** @var mixed $flashed */
        $flashed = $request->session()->get(RestrictToTwoFactorSetup::RECOVERY_CODES_FLASH_KEY);
        $recoveryCodes = is_array($flashed) ? array_values($flashed) : null;

        return view('auth.two-factor-settings', [
            'setupMode' => (bool) $request->session()->get(RestrictToTwoFactorSetup::SESSION_KEY, false),
            'enrolled' => $user->hasEnabledTwoFactorAuthentication(),
            'hasPendingSecret' => $user->two_factor_secret !== null,
            'confirmedAt' => $user->two_factor_confirmed_at,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }
}
