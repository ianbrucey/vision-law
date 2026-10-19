<x-layouts.app :title="$item->title . ' · Vision Law'">
    <x-ui.page-header :title="$item->title" :subtitle="$matter->matter_number . ' · ' . $matter->title">
        <x-slot name="crumbs">
            <a href="{{ route('matters.calendar', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matter calendar</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>{{ $item->title }}</span>
        </x-slot>
        <x-slot name="actions">
            <x-ui.status type="calendar_item" :value="$item->status" />
            <x-ui.chip tone="neutral">{{ ucfirst($item->type) }}</x-ui.chip>
            @if ($item->classification)
                <x-ui.chip tone="neutral">{{ ucfirst($item->classification) }}</x-ui.chip>
            @endif
            @if ($canEdit)
                <x-ui.button variant="primary" href="{{ route('calendar.items.edit', $item) }}">Edit</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Details">
        <x-ui.kv :items="[
            ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', $item->status))],
            ['label' => 'Type', 'value' => ucfirst($item->type)],
            ['label' => 'Due date', 'value' => $item->due_date?->toDateString() ?? '—'],
            ['label' => 'Starts', 'value' => $item->starts_at ? fmtDT($item->starts_at->copy()->setTimezone($item->timezone ?: 'UTC')) . ' ' . ($item->timezone ?: 'UTC') : '—'],
            ['label' => 'Ends', 'value' => $item->ends_at ? fmtDT($item->ends_at->copy()->setTimezone($item->timezone ?: 'UTC')) . ' ' . ($item->timezone ?: 'UTC') : '—'],
            ['label' => 'Cutoff', 'value' => $item->cutoff_time ? substr((string) $item->cutoff_time, 0, 5) : '—'],
            ['label' => 'Jurisdiction', 'value' => $item->jurisdiction ?? '—'],
            ['label' => 'Assignee', 'value' => $item->assignee?->name ?? 'Unassigned'],
            ['label' => 'Timezone', 'value' => $item->timezone ?? 'UTC'],
            ['label' => 'Location', 'value' => $item->location ?? '—'],
            ['label' => 'Version', 'value' => (string) $item->version],
        ]" />
        @if ($item->description)
            <p class="mt-4">{{ $item->description }}</p>
        @endif
        @if ($item->supersededBy)
            <p class="mt-4">Superseded by <a href="{{ route('calendar.items.show', $item->supersededBy) }}" class="underline">{{ $item->supersededBy->title }}</a></p>
        @endif
        @if ($item->supersedes)
            <p class="mt-4">Supersedes <a href="{{ route('calendar.items.show', $item->supersedes) }}" class="underline">{{ $item->supersedes->title }}</a></p>
        @endif
    </x-ui.card>

    @if ($canEdit && !in_array($item->status, ['superseded', 'canceled'], true))
        <x-ui.card title="Lifecycle" class="mt-6">
            <div class="flex flex-wrap gap-2.5">
                @if (in_array($item->status, ['draft', 'at_risk'], true))
                    <form method="POST" action="{{ route('calendar.items.confirm', $item) }}">@csrf<x-ui.button variant="primary" type="submit">Confirm</x-ui.button></form>
                @endif
                @if (in_array($item->status, ['draft', 'confirmed', 'at_risk'], true))
                    <form method="POST" action="{{ route('calendar.items.complete', $item) }}">@csrf<x-ui.button variant="secondary" type="submit">Complete</x-ui.button></form>
                    <form method="POST" action="{{ route('calendar.items.cancel', $item) }}">@csrf<x-ui.field name="reason" label="Reason (required for cancel)" /><x-ui.button variant="danger" type="submit">Cancel</x-ui.button></form>
                @endif
                @if (in_array($item->status, ['completed'], true))
                    <form method="POST" action="{{ route('calendar.items.reopen', $item) }}">@csrf<x-ui.button variant="secondary" type="submit">Reopen</x-ui.button></form>
                @endif
            </div>
        </x-ui.card>
    @endif

    @if ($item->type === 'task')
        <x-ui.card title="Task" class="mt-6">
            <x-ui.kv :items="[
                ['label' => 'Task status', 'value' => ucfirst(str_replace('_', ' ', $item->task_status ?? 'not_started'))],
                ['label' => 'Completed', 'value' => $item->completed_at ? $item->completed_at->toDateTimeString() . ($item->completedBy ? ' by ' . $item->completedBy->name : '') : '—'],
            ]" />
            @if ($canEdit)
                <form method="POST" action="{{ route('calendar.tasks.assign', $item) }}" class="mt-4">
                    @csrf
                    <x-ui.select name="assignee_id" label="Assignee" :options="$taskUsers->mapWithKeys(fn ($u) => [(string) $u->getKey() => $u->name])->all()" :value="$item->assignee_id" />
                    <x-ui.button variant="primary" type="submit">Assign</x-ui.button>
                </form>
                <div class="flex flex-wrap gap-2.5 mt-4">
                    @foreach (\App\Models\CalendarItem::TASK_STATUSES as $taskStatus)
                        <form method="POST" action="{{ route('calendar.tasks.status', $item) }}">
                            @csrf
                            <input type="hidden" name="task_status" value="{{ $taskStatus }}">
                            @if ($taskStatus === 'completed')
                                <x-ui.field name="override_reason" label="Override reason (only to complete with open required checklist items)" optional />
                            @endif
                            <x-ui.button variant="secondary" type="submit">{{ ucfirst(str_replace('_', ' ', $taskStatus)) }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            @endif

            <h3 class="font-semibold mt-6 mb-2">Checklist</h3>
            @forelse ($item->checklistItems as $entry)
                <div class="flex items-center gap-3 py-1">
                    @if ($canEdit)
                        <form method="POST" action="{{ route('calendar.tasks.checklist.toggle', [$item, $entry]) }}">
                            @csrf
                            <input type="hidden" name="done" value="{{ $entry->done ? '0' : '1' }}">
                            <x-ui.button variant="secondary" type="submit">{{ $entry->done ? 'Reopen' : 'Done' }}</x-ui.button>
                        </form>
                    @endif
                    <span>{{ $entry->done ? '✓' : '○' }} {{ $entry->label }} @if ($entry->required) (required) @endif</span>
                </div>
            @empty
                <p>No checklist items.</p>
            @endforelse

            <h3 class="font-semibold mt-6 mb-2">Dependencies</h3>
            <p>Depends on:</p>
            @forelse ($item->dependencies as $edge)
                <div class="flex items-center gap-3 py-1">
                    <a href="{{ route('calendar.items.show', $edge->predecessor) }}" class="underline">{{ $edge->predecessor->title }}</a>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('calendar.tasks.dependencies.destroy', [$item, $edge->predecessor]) }}">
                            @csrf @method('DELETE')
                            <x-ui.button variant="danger" type="submit">Remove</x-ui.button>
                        </form>
                    @endif
                </div>
            @empty
                <p>None.</p>
            @endforelse
            <p class="mt-3">Blocks:</p>
            @forelse ($item->dependents as $edge)
                <p><a href="{{ route('calendar.items.show', $edge->successor) }}" class="underline">{{ $edge->successor->title }}</a></p>
            @empty
                <p>None.</p>
            @endforelse
            @if ($canEdit)
                <form method="POST" action="{{ route('calendar.tasks.dependencies.store', $item) }}" class="mt-4">
                    @csrf
                    <x-ui.select name="predecessor_id" label="Depends on" :options="$taskCandidates->mapWithKeys(fn ($t) => [(string) $t->getKey() => $t->title])->all()" />
                    <x-ui.button variant="primary" type="submit">Add dependency</x-ui.button>
                </form>
                <form method="POST" action="{{ route('calendar.tasks.dependencies.apply-move', $item) }}" class="mt-4">
                    @csrf
                    <x-ui.field name="new_due_date" label="Move this task's due date" type="date" :value="$item->due_date?->toDateString()" />
                    <x-ui.button variant="secondary" type="submit">Apply move (shifts blocked tasks)</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @endif

    @if ($item->series_id)
        <x-ui.card title="Recurrence" class="mt-6">
            <p>Series occurrence {{ $item->occurrence_date?->toDateString() ?? '—' }}@if ($item->recurrence_detached) (detached from series edits)@endif</p>
            @if ($canEdit)
                <form method="POST" action="{{ route('calendar.items.recurrence', $item) }}" class="mt-4">
                    @csrf
                    <x-ui.select name="scope" label="Edit scope" :options="['this' => 'This occurrence only', 'following' => 'This and following', 'series' => 'Whole series (future occurrences)']" value="this" />
                    <x-ui.field name="title" label="Title" :value="$item->title" />
                    <x-ui.button variant="primary" type="submit">Apply recurrence edit</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="Explanation" class="mt-6">
        <p>{{ $explanation['summary'] ?? 'Manual entry' }}</p>
        <x-ui.kv :items="[
            ['label' => 'Source', 'value' => (string) ($explanation['source'] ?? $item->source)],
            ['label' => 'Actor', 'value' => (string) ($explanation['actor'] ?? '—')],
            ['label' => 'Rule', 'value' => (string) ($explanation['rule_key'] ?? '—')],
            ['label' => 'Anchor', 'value' => is_array($explanation['anchor'] ?? null) ? json_encode($explanation['anchor']) : (string) ($explanation['anchor'] ?? '—')],
            ['label' => 'Rule version', 'value' => (string) ($explanation['rule_version'] ?? $explanation['pack_version'] ?? '—')],
        ]" />
        @if (!empty($explanation['steps']))
            <ol class="list-decimal ml-5 mt-3 space-y-1">
                @foreach ($explanation['steps'] as $step)
                    <li>{{ is_array($step) ? ($step['description'] ?? json_encode($step)) : $step }}</li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
</x-layouts.app>
