<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
    public function create(): View
    {
        return view('auth.login');
    }
}
