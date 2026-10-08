{{--
    templates/edit.blade.php — spec 007 T-04. Editing bumps the template version.
    Expects: $template.
--}}
@php
    $tTitle = 'Edit '.$template->name.' · Vision Law';
@endphp
<x-layouts.app :title="$tTitle">
    <x-ui.page-header :title="'Edit template'" :subtitle="$template->name . ' · currently v' . $template->version">
        <x-slot name="actions">
            <x-ui.button variant="secondary" href="{{ route('templates.show', $template) }}" class="min-h-[44px]">Back</x-ui.button>
        </x-slot>
    </x-ui.page-header>
    <x-ui.card>
        @include('templates._form', ['template' => $template, 'action' => route('templates.update', $template), 'method' => 'PATCH'])
    </x-ui.card>
</x-layouts.app>
