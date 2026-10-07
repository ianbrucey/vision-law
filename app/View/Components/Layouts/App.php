<?php

namespace App\View\Components\Layouts;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * <x-layouts.app> — the app shell (spec 002 T-05, 03-contract.md §Layout).
 *
 * Class bridge so the shell keeps its contract path
 * (resources/views/layouts/app.blade.php) while views consume it as a
 * component with named slots (content, header, nav, org, userMenu, footer).
 */
class App extends Component
{
    public function __construct(
        public string $title = 'Vision Law',
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
