<?php

namespace App\View\Components\Layouts;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The public marketing + auth shell (spec 003 T-01, 05-ui.md layout contract).
 *
 * Class bridge so the shell keeps its contract path
 * (resources/views/layouts/public.blade.php) while views consume it as a
 * component with a single $slot, mirroring <x-layouts.app>. Registered under
 * the alias "layouts.public" in AppServiceProvider because `Public` is a PHP
 * reserved word and cannot be a class name.
 */
class PublicLayout extends Component
{
    public function __construct(
        public string $title = 'Vision Law',
    ) {}

    public function render(): View
    {
        return view('layouts.public');
    }
}
