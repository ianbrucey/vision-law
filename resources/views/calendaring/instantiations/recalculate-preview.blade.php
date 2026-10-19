<x-layouts.app :title="'Recalculation preview · ' . $matter->title . ' · Vision Law'">
    <x-ui.page-header title="Recalculation preview" subtitle="Impact of the new trigger facts on this instantiation. Nothing has changed yet — apply to supersede the affected items; manually overridden items are preserved unless you reset overrides.">
        <x-slot name="crumbs">
            <a href="{{ route('matters.calendar', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">{{ $matter->matter_number }}</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>Recalculation preview</span>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Impact" class="mb-6">
        @forelse ($entries as $entry)
            <div class="flex flex-wrap items-baseline gap-3 py-3 border-t border-vl-line first:border-t-0">
                <span class="font-semibold text-vl-ink">{{ $entry['item']->title }}</span>
                <span class="text-[13px] text-vl-mut">{{ $entry['old_date'] }} → {{ $entry['new_date'] ?? '—' }}</span>
                @if ($entry['preserved'])
                    <x-ui.chip tone="neutral">Manual override — preserved</x-ui.chip>
                @elseif ($entry['changed'])
                    <x-ui.chip tone="neutral">Will be superseded</x-ui.chip>
                @else
                    <x-ui.chip tone="neutral">Unchanged</x-ui.chip>
                @endif
            </div>
        @empty
            <p class="text-[14.5px] text-vl-mut">No live items in this instantiation.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Apply recalculation">
        <form method="POST" action="{{ route('calendar.instantiations.recalculate', [$matter, $batch]) }}">
            @csrf
            <input type="hidden" name="inputs[trigger_date]" value="{{ $inputs['trigger_date'] ?? '' }}">
            <input type="hidden" name="inputs[timezone]" value="{{ $inputs['timezone'] ?? 'UTC' }}">
            <x-ui.checkbox name="reset_overrides" label="Reset manual overrides (supersede overridden items too)" :checked="$resetOverrides" />
            <x-ui.button variant="primary" type="submit">Apply recalculation</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.app>
