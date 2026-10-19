<x-layouts.app :title="'Calendar · ' . $matter->title . ' · Vision Law'">
    <x-ui.page-header :title="$matter->title" subtitle="Matter calendar — deadlines, events, and tasks for this matter.">
        <x-slot name="crumbs">
            <a href="{{ route('matters.show', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">{{ $matter->matter_number }}</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>Calendar</span>
        </x-slot>
        <x-slot name="actions">
            <x-ui.button variant="secondary" href="{{ route('calendar.index') }}">Unified agenda</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($canEdit)
        <x-ui.card title="Quick add" class="mb-6">
            <form method="POST" action="{{ route('calendar.items.store', $matter) }}">
                @csrf
                <x-ui.field name="title" label="Title" required />
                <div class="grid gap-x-5 min-[821px]:grid-cols-2">
                    <x-ui.select name="type" label="Type" :options="['deadline' => 'Deadline', 'event' => 'Event', 'task' => 'Task']" required />
                    <x-ui.field name="due_date" label="Due date" type="date" />
                </div>
                <input type="hidden" name="timezone" value="UTC">
                <input type="hidden" name="status" value="draft">
                <x-ui.button variant="primary" type="submit">Add item</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Full editor" class="mb-6">
            @include('calendaring.items._form', [
                'action' => route('calendar.items.store', $matter),
                'httpMethod' => 'POST',
                'submitLabel' => 'Create item',
                'item' => null,
                'cancelHref' => route('matters.calendar', $matter),
            ])
        </x-ui.card>
    @endif

    <x-ui.card title="Deadline templates" class="mb-6">
        @if ($canEdit)
            <form method="POST" action="{{ route('calendar.instantiations.preview', $matter) }}">
                @csrf
                <x-ui.select name="template_version_id" label="Published template version" :options="$publishedVersions->mapWithKeys(fn ($version) => [(string) $version->getKey() => ($version->template?->name ?? 'Template') . ' — v' . $version->version])->all()" required />
                <div class="grid gap-x-5 min-[821px]:grid-cols-2">
                    <x-ui.field name="trigger_date" label="Trigger date (e.g. date of service)" type="date" required />
                    <x-ui.field name="timezone" label="Time zone" value="America/New_York" required />
                </div>
                <x-ui.button variant="primary" type="submit">Preview deadlines</x-ui.button>
            </form>
        @endif

        @forelse ($instantiationBatches as $batch)
            <div class="flex flex-wrap items-baseline gap-3 py-3 border-t border-vl-line">
                <span class="font-semibold text-vl-ink">{{ $batch->templateVersion?->template?->name ?? 'Template' }} — v{{ $batch->templateVersion?->version }}</span>
                <x-ui.chip tone="neutral">{{ $batch->items_count }} items</x-ui.chip>
                <span class="text-[13px] text-vl-mut">Trigger: {{ $batch->inputs['trigger_date'] ?? '' }}</span>
                <span class="text-[13px] text-vl-mut">Instantiated {{ $batch->created_at?->toDateString() }}</span>
            </div>
        @empty
            <p class="text-[14.5px] text-vl-mut pt-3">No template instantiations for this matter yet.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Items">
        @forelse ($items as $item)
            <div class="flex flex-wrap items-baseline gap-3 py-3 border-t border-vl-line first:border-t-0">
                <a href="{{ route('calendar.items.show', $item) }}" class="font-semibold text-vl-ink underline">{{ $item->title }}</a>
                <x-ui.status type="calendar_item" :value="$item->status" />
                <x-ui.chip tone="neutral">{{ ucfirst($item->type) }}</x-ui.chip>
                <span class="text-[13px] text-vl-mut">{{ $item->due_date?->toDateString() ?? $item->starts_at?->toDateString() ?? '' }}</span>
                <span class="text-[13px] text-vl-mut">{{ $item->assignee?->name ?? 'Unassigned' }}</span>
                @if ($item->getAttribute('changed'))
                    <span class="text-[13px] font-semibold">Changed: {{ $item->getAttribute('changed_from') }} → {{ $item->getAttribute('changed_to') }}</span>
                @endif
            </div>
        @empty
            <p class="text-[14.5px] text-vl-mut">No calendar items for this matter yet.</p>
        @endforelse
    </x-ui.card>
</x-layouts.app>
