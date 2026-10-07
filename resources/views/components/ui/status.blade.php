{{--
    View for App\View\Components\Ui\Status. Delegates to <x-ui.chip> so the
    tone class map stays in exactly one place.
--}}
<x-ui.chip :tone="$tone">{{ $label }}</x-ui.chip>
