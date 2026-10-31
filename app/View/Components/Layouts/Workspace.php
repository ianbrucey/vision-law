<?php

namespace App\View\Components\Layouts;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-layouts.workspace> — the Spec 012 Glass/Aurora shell (Slice 1).
 *
 * Class bridge so the shell keeps its contract path
 * (resources/views/layouts/workspace.blade.php) while views consume it as
 * a component, mirroring <x-layouts.app>. Deliberately separate from the
 * spec 002 light shell: the Brain surface is dark-only
 * (design/STYLE_GUIDE.md §8) with its own scoped stylesheet.
 */
class Workspace extends Component
{
    public function __construct(
        public string $title = 'Vision Law',
    ) {}

    public function render(): View
    {
        return view('layouts.workspace');
    }
}
