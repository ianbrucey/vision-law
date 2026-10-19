<x-layouts.app title="Task triage · Vision Law">
    <x-ui.page-header title="Task triage" subtitle="Unassigned confirmed tasks across your matters" />

    <x-ui.card title="Unassigned tasks">
        @forelse ($items as $item)
            <p class="py-1">
                <a href="{{ route('calendar.items.show', $item) }}" class="underline">{{ $item->title }}</a>
                <span>· {{ $item->matter->title }} · due {{ $item->due_date?->toDateString() ?? '—' }} · {{ ucfirst(str_replace('_', ' ', $item->task_status ?? 'not_started')) }}</span>
            </p>
        @empty
            <p>No unassigned tasks need triage.</p>
        @endforelse
    </x-ui.card>
</x-layouts.app>
