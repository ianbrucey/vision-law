<x-layouts.app :title="'Edit · ' . $item->title . ' · Vision Law'">
    <x-ui.page-header :title="$item->title" subtitle="Edit calendar item">
        <x-slot name="crumbs">
            <a href="{{ route('matters.calendar', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matter calendar</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>{{ $item->title }}</span>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card>
        @include('calendaring.items._form', [
            'action' => route('calendar.items.update', $item),
            'httpMethod' => 'PATCH',
            'submitLabel' => 'Save changes',
            'item' => $item,
            'cancelHref' => route('calendar.items.show', $item),
        ])
    </x-ui.card>
</x-layouts.app>
