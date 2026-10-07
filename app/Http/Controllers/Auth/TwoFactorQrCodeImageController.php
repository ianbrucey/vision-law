<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Spec 005 T-03 (follow-up flagged by T-01) — the enrollment QR as a real
 * image.
 *
 * Fortify's two-factor.qr-code endpoint returns JSON {svg, url}, NOT an
 * image — so 005-D04's `<img src="…">` cannot point at it (an <img> cannot
 * render JSON). This app-level endpoint serves Fortify's QR SVG bytes as
 * image/svg+xml instead. The TOTP secret / otpauth bytes travel in this
 * image response only — they never appear in the enrollment page's HTML
 * source, which preserves 005-D04's intent (no secret/otpauth bytes in
 * page source, no inline SVG in the enrollment HTML).
 *
 * Route: GET user/two-factor-qr-code.svg, named two-factor.qr-image.
 * Middleware mirrors Fortify's own QR route (auth + password.confirm).
 * 404 when the user has no pending secret — there is no QR to serve.
 */
class TwoFactorQrCodeImageController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null) {
            abort(404);
        }

        return response(
            $user->twoFactorQrCodeSvg(),
            200,
            ['Content-Type' => 'image/svg+xml']
        );
    }
}
