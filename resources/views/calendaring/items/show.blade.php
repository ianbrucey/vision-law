<x-layouts.app :title="$item->title . ' · Vision Law'">
    <x-ui.page-header :title="$item->title" :subtitle="$matter->matter_number . ' · ' . $matter->title">
        <x-slot name="actions">
            <x-ui.status type="calendar_item" :value="$item->status" />
            <x-ui.chip tone="neutral">{{ ucfirst($item->type) }}</x-ui.chip>
            @if ($item->classification)
                <x-ui.chip tone="neutral">{{ ucfirst($item->classification) }}</x-ui.chip>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Details">
        <x-ui.kv :items="[
            ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', $item->status))],
            ['label' => 'Type', 'value' => ucfirst($item->type)],
            ['label' => 'Due date', 'value' => $item->due_date?->toDateString() ?? '—'],
            ['label' => 'Cutoff', 'value' => $item->cutoff_time ? substr((string) $item->cutoff_time, 0, 5) : '—'],
            ['label' => 'Jurisdiction', 'value' => $item->jurisdiction ?? '—'],
            ['label' => 'Assignee', 'value' => $item->assignee?->name ?? 'Unassigned'],
            ['label' => 'Timezone', 'value' => $item->timezone ?? 'UTC'],
            ['label' => 'Location', 'value' => $item->location ?? '—'],
        ]" />
        @if ($item->description)
            <p class="mt-4">{{ $item->description }}</p>
        @endif
    </x-ui.card>

    <x-ui.card title="Explanation" class="mt-6">
        <p>{{ $explanation['summary'] ?? 'Manual entry' }}</p>
        <p class="text-[14px] text-vl-mut">Source: {{ $explanation['source'] ?? $item->source }}</p>
        @if (!empty($explanation['steps']))
            <ol class="list-decimal ml-5 mt-2">
                @foreach ($explanation['steps'] as $step)
                    <li>{{ is_array($step) ? json_encode($step) : $step }}</li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
</x-layouts.app>
