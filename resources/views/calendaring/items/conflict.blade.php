<x-layouts.app title="Edit conflict · Vision Law">
    <x-ui.page-header title="This item changed while you were editing" subtitle="Your save was not applied — no silent overwrite. Review the current version below." />
    <x-ui.card title="Current version">
        <x-ui.kv :items="[
            ['label' => 'Title', 'value' => $item->title],
            ['label' => 'Status', 'value' => ucfirst($item->status)],
            ['label' => 'Due date', 'value' => $item->due_date?->toDateString() ?? '—'],
            ['label' => 'Version', 'value' => (string) $item->version],
        ]" />
        <div class="mt-4">
            <x-ui.button variant="primary" href="{{ route('calendar.items.show', $item) }}">View current item</x-ui.button>
        </div>
    </x-ui.card>
</x-layouts.app>
