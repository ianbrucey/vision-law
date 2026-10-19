{{--
    Guest view for a shared calendar item (009 T-14, 009-D22).
    Renders ONLY the share's allowlisted fields. The matter title is
    NOT in the allowlist and is never rendered here; neither are
    internal notes, watchers, or attendees.
    Expects: $share, $item, $fields (list<string>).
--}}
@php
    $rows = [];
    if (in_array('date', $fields, true)) {
        if ($item->due_date !== null) {
            $rows[] = ['label' => 'Date', 'value' => $item->due_date->toDateString()];
        } elseif ($item->starts_at !== null) {
            $rows[] = ['label' => 'Date', 'value' => $item->starts_at->copy()->setTimezone($item->timezone ?: 'UTC')->toDateString()];
        }
    }
    if (in_array('time', $fields, true) && $item->starts_at !== null) {
        $rows[] = ['label' => 'Time', 'value' => $item->starts_at->copy()->setTimezone($item->timezone ?: 'UTC')->format('H:i') . ' ' . ($item->timezone ?: 'UTC')];
    }
    if (in_array('location', $fields, true) && $item->location) {
        $rows[] = ['label' => 'Location', 'value' => (string) $item->location];
    }
    if (in_array('description', $fields, true) && $item->description) {
        $rows[] = ['label' => 'Description', 'value' => (string) $item->description];
    }
@endphp
<x-layouts.public title="Shared calendar item · Vision Law">
    <div class="mx-auto max-w-[720px] px-4 py-10">
        <x-ui.card title="Shared calendar item">
            @if (in_array('title', $fields, true))
                <p class="text-[18px] font-semibold text-vl-ink mb-4">{{ $item->title }}</p>
            @endif
            @if ($rows !== [])
                <x-ui.kv :items="$rows" />
            @endif
        </x-ui.card>
    </div>
</x-layouts.public>
