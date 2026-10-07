<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Spec 003 T-05: the sign-in view.
 *
 * Fortify is headless (config/fortify.php: views => false), so the GET view
 * route is app-level; the POST /login backend stays Fortify's. Pure guest
 * view — no auth session data is rendered here (C-04).
 */
class LoginController extends Controller
{
    public function create(Request $request): View
    {
        // Spec 004: the invitation accept page links here with ?next= back
        // to the token URL so an existing invitee returns after signing in.
        // Only a relative in-app path is honored — never an external URL
        // (open-redirect safe).
        $next = $request->query('next');
        if (is_string($next) && $next !== '' && str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
            redirect()->setIntendedUrl($next);
        }

        return view('auth.login');
    }
}
