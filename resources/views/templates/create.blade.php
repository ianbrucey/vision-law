{{--
    templates/create.blade.php — spec 007 T-04. New template draft.
--}}
<x-layouts.app title="New template · Vision Law">
    <x-ui.page-header title="New template" subtitle="Stationery with merge fields — no drafting intelligence (007-D03)." />
    <x-ui.card>
        @include('templates._form', ['template' => null, 'action' => route('templates.store'), 'method' => 'POST'])
    </x-ui.card>
</x-layouts.app>
