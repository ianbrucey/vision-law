<x-layouts.app :title="'Instantiation preview · ' . $matter->title . ' · Vision Law'">
    <x-ui.page-header :title="'Preview: ' . ($version->template?->name ?? 'Deadline template')" subtitle="Calculated dates from the published template version. Nothing has been created yet — confirm to create these items on the matter.">
        <x-slot name="crumbs">
            <a href="{{ route('matters.calendar', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">{{ $matter->matter_number }}</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>Instantiation preview</span>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Calculated items" class="mb-6">
        @forelse ($items as $item)
            <div class="flex flex-wrap items-baseline gap-3 py-3 border-t border-vl-line first:border-t-0">
                <span class="font-semibold text-vl-ink">{{ $item['title'] ?? $item['rule_key'] }}</span>
                <x-ui.chip tone="neutral">{{ ucfirst((string) ($item['kind'] ?? 'deadline')) }}</x-ui.chip>
                <span class="text-[13px] text-vl-mut">{{ $item['result_date'] ?? '' }}</span>
                <span class="text-[13px] text-vl-mut">{{ $item['explanation'] ?? '' }}</span>
            </div>
        @empty
            <p class="text-[14.5px] text-vl-mut">This template version calculates no items for the given inputs.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Confirm instantiation">
        <form method="POST" action="{{ route('calendar.instantiations.store', $matter) }}">
            @csrf
            <input type="hidden" name="template_version_id" value="{{ $version->getKey() }}">
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <input type="hidden" name="inputs[trigger_date]" value="{{ $inputs['trigger_date'] ?? '' }}">
            <input type="hidden" name="inputs[timezone]" value="{{ $inputs['timezone'] ?? 'UTC' }}">
            <p class="text-[14.5px] text-vl-mut mb-4">Confirming creates the items above as confirmed deadlines on this matter, with their calculation explanations stored as provenance.</p>
            <x-ui.button variant="primary" type="submit">Confirm instantiation</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.app>
