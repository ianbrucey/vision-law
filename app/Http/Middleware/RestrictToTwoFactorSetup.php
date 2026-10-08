<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 005 (005-D01): restricted "setup-mode" session for org admins without
 * confirmed 2FA.
 *
 * When the session carries visionlaw.2fa_setup_required, the session may
 * reach ONLY the enrollment surface. The allowlist below is explicit — route
 * names, never a denylist. Anything else 302s to the enrollment page (no
 * 403/404 games: the session is authenticated and the restriction is
 * explained on the page itself).
 *
 * Registered on the web group (bootstrap/app.php) so every web route is
 * covered by default, including routes added later. Without the flag this
 * middleware is a no-op.
 */
class RestrictToTwoFactorSetup
{
    /**
     * Session key marking a restricted setup-mode session. Set by
     * EnforceSessionPolicies on login; cleared on two-factor.confirm. Lives
     * in the session only — never persisted to the user row (03-contract.md).
     */
    public const SESSION_KEY = 'visionlaw.2fa_setup_required';

    /**
     * Session flash key carrying the once-display recovery codes from the
     * confirm/regenerate POST handler to the next enrollment-page GET.
     * Flash data survives exactly one request, so a refresh or a later
     * visit renders status only.
     */
    public const RECOVERY_CODES_FLASH_KEY = 'two_factor.recovery_codes';

    /**
     * Route names reachable inside a setup-mode session (03-contract.md
     * § setup-mode allowlist): the enrollment page, its POST/DELETE
     * actions, the QR/secret-key helpers, the spec 008 passkey actions,
     * and logout.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [
        'two-factor.settings', // GET user/two-factor — the enrollment page
        'two-factor.enable', // POST user/two-factor-authentication
        'two-factor.confirm', // POST user/confirmed-two-factor-authentication
        'two-factor.disable', // DELETE user/two-factor-authentication
        'two-factor.regenerate-recovery-codes', // POST user/two-factor-recovery-codes
        'two-factor.qr-code', // GET user/two-factor-qr-code
        'two-factor.qr-image', // GET user/two-factor-qr-code.svg (T-03: the <img>-able QR)
        'two-factor.secret-key', // GET user/two-factor-secret-key (manual-key fallback)
        // Spec 008 (03-contract.md): the passkey path through enrollment —
        // a setup-mode admin may complete setup with a passkey instead of
        // an authenticator app (C-01/C-03).
        'passkeys.register.options', // POST user/passkeys/register/options
        'passkeys.register', // POST user/passkeys
        'passkeys.destroy', // DELETE user/passkeys/{credential}
        'logout', // POST /logout
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get(self::SESSION_KEY, false)) {
            return $next($request);
        }

        $route = $request->route();

        if ($route !== null && in_array($route->getName(), self::ALLOWLIST, true)) {
            return $next($request);
        }

        // /up (health check) runs outside the web group, so this middleware
        // never sees it; allowlisted by path here as defense in depth.
        if ($request->is('up')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user instanceof User) {
            AuditLogger::log('user.2fa_setup.denied', $user, [
                'attempted_path' => '/'.ltrim($request->path(), '/'),
            ]);
        }

        return redirect()->route('two-factor.settings');
    }
}
