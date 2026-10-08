{{--
    matters/edit.blade.php — spec 006 T-05, edit matter.
--}}
<x-layouts.app :title="'Edit · ' . $matter->title . ' · Vision Law'">
    <x-ui.page-header
        title="Edit matter"
        :subtitle="$matter->matter_number . ' · ' . $matter->title"
    >
        <x-slot name="crumbs">
            <a href="{{ route('matters.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matters</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <a href="{{ route('matters.show', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">{{ $matter->matter_number }}</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>Edit</span>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Matter details">
        @include('matters._form', [
            'action' => route('matters.update', $matter),
            'httpMethod' => 'PATCH',
            'submitLabel' => 'Save changes',
            'typeOptions' => $typeOptions,
            'matter' => $matter,
            'cancelHref' => route('matters.show', $matter),
        ])
    </x-ui.card>
</x-layouts.app>
