<x-layouts.app title="Calendar · Vision Law">
    <x-ui.page-header title="Calendar" subtitle="What is due, who owns it, and what changed — across the matters you can access.">
        <x-slot name="actions">
            @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'list' => 'List'] as $m => $label)
                <x-ui.button :variant="$mode === $m ? 'primary' : 'secondary'" href="{{ route('calendar.index', ['mode' => $m, 'anchor' => $anchor]) }}">{{ $label }}</x-ui.button>
            @endforeach
        </x-slot>
    </x-ui.page-header>

    <div class="grid gap-4 min-[821px]:grid-cols-3 mb-6">
        <x-ui.stat :value="$counts['total']" label="Items in view" />
        <x-ui.stat :value="$counts['by_status']['confirmed'] ?? 0" label="Confirmed" />
        <x-ui.stat :value="$counts['by_status']['completed'] ?? 0" label="Completed" />
    </div>

    <x-ui.card title="Filters" class="mb-6">
        <form method="GET" action="{{ route('calendar.index') }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <div class="grid gap-x-5 min-[821px]:grid-cols-2">
                <x-ui.field name="anchor" label="Anchor date" type="date" :value="$anchor" />
                <x-ui.field name="search" label="Search" :value="$filters['search'] ?? null" />
                <x-ui.select name="type" label="Type" :options="['deadline' => 'Deadline', 'event' => 'Event', 'task' => 'Task']" placeholder="All types" :value="($filters['types'] ?? [])[0] ?? null" />
                <x-ui.select name="status" label="Status" :options="array_combine(\App\Models\CalendarItem::STATUSES, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), \App\Models\CalendarItem::STATUSES))" placeholder="All statuses" :value="($filters['statuses'] ?? [])[0] ?? null" />
            </div>
            <x-ui.button variant="primary" type="submit">Apply filters</x-ui.button>
        </form>
        <form method="POST" action="{{ route('calendar.filters') }}" class="mt-3">
            @csrf
            <input type="hidden" name="mode" value="{{ $mode }}">
            <input type="hidden" name="anchor" value="{{ $anchor }}">
            @if (!empty($filters['search']))<input type="hidden" name="search" value="{{ $filters['search'] }}">@endif
            @foreach (($filters['types'] ?? []) as $t)<input type="hidden" name="types[]" value="{{ $t }}">@endforeach
            @foreach (($filters['statuses'] ?? []) as $s)<input type="hidden" name="statuses[]" value="{{ $s }}">@endforeach
            <x-ui.button variant="secondary" type="submit">Save these filters</x-ui.button>
        </form>
    </x-ui.card>

    @if ($mode === 'month')
        <x-ui.card title="Month" class="mb-6">
            <div class="grid grid-cols-7 gap-1 text-[12px]">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
                    <div class="font-bold text-vl-mut px-1 py-1">{{ $dow }}</div>
                @endforeach
                @php
                    $monthStart = \Illuminate\Support\Carbon::parse($result['start']);
                    $lead = ((int) $monthStart->dayOfWeekIso) - 1;
                    $daysInMonth = $monthStart->daysInMonth;
                @endphp
                @for ($i = 0; $i < $lead; $i++)<div></div>@endfor
                @for ($d = 1; $d <= $daysInMonth; $d++)
                    @php $dateKey = $monthStart->copy()->day($d)->toDateString(); @endphp
                    <div class="border border-vl-line rounded p-1 min-h-[64px]">
                        <div class="font-semibold">{{ $d }}</div>
                        @foreach (($groups[$dateKey] ?? []) as $gridItem)
                            <div class="truncate" title="{{ $gridItem->title }}">{{ $gridItem->title }}</div>
                        @endforeach
                    </div>
                @endfor
            </div>
        </x-ui.card>
    @endif

    @forelse ($groups as $date => $groupItems)
        <x-ui.card :title="$date === 'undated' ? 'Undated' : $date" class="mb-4">
            <div>
                @foreach ($groupItems as $item)
                    <div class="flex flex-wrap items-baseline gap-3 py-3 border-t border-vl-line first:border-t-0">
                        <a href="{{ route('calendar.items.show', $item) }}" class="font-semibold text-vl-ink underline">{{ $item->title }}</a>
                        <x-ui.status type="calendar_item" :value="$item->status" />
                        <x-ui.chip tone="neutral">{{ ucfirst($item->type) }}</x-ui.chip>
                        <span class="text-[13px] text-vl-mut">{{ $item->matter?->title }}</span>
                        <span class="text-[13px] text-vl-mut">{{ $item->assignee?->name ?? 'Unassigned' }}</span>
                        @if ($item->getAttribute('changed'))
                            <span class="text-[13px] font-semibold">Changed: {{ $item->getAttribute('changed_from') }} → {{ $item->getAttribute('changed_to') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty title="Nothing in this view">
            <p>No calendar items match this window and filters for the matters you can access.</p>
        </x-ui.empty>
    @endforelse
</x-layouts.app>
