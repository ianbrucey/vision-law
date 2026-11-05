<?php

namespace App\View\Components\Layouts;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-layouts.dashboard> — the Spec 012 Glass/Aurora home shell
 * (home redesign, 2026-11-04).
 *
 * Class bridge so the shell keeps its contract path
 * (resources/views/layouts/dashboard.blade.php) while views consume it
 * as a component, mirroring <x-layouts.workspace>. Deliberately
 * separate from the spec 002 light shell: the dashboard is a dark
 * Glass/Aurora surface (design/STYLE_GUIDE.md) with its own scoped
 * stylesheet and shell script.
 */
class Dashboard extends Component
{
    public function __construct(
        public string $title = 'Vision Law',
    ) {}

    public function render(): View
    {
        return view('layouts.dashboard');
    }
}
