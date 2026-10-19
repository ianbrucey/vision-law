{{-- Editor form for a calendar item. Expects: $action, $httpMethod, $submitLabel, $item (CalendarItem|null), $cancelHref --}}
<form method="POST" action="{{ $action }}">
    @csrf
    @if ($httpMethod !== 'POST')
        @method($httpMethod)
    @endif
    @if ($item)
        <input type="hidden" name="version" value="{{ $item->version }}">
    @endif

    <x-ui.field name="title" label="Title" required :value="$item?->title" />
    <div class="grid gap-x-5 min-[821px]:grid-cols-2">
        <x-ui.select name="type" label="Type" :options="['deadline' => 'Deadline', 'event' => 'Event', 'task' => 'Task']" required :value="$item?->type ?? 'deadline'" />
        <x-ui.select name="classification" label="Classification" :options="['hard' => 'Hard', 'soft' => 'Soft', 'internal' => 'Internal']" :value="$item?->classification" />
    </div>
    <x-ui.textarea name="description" label="Description" optional rows="3" :value="$item?->description" />
    <div class="grid gap-x-5 min-[821px]:grid-cols-2">
        <x-ui.field name="due_date" label="Due date" type="date" :value="$item?->due_date?->toDateString()" />
        <x-ui.field name="cutoff_time" label="Cutoff time" type="time" :value="$item && $item->cutoff_time ? substr((string) $item->cutoff_time, 0, 5) : null" />
        <x-ui.field name="starts_at" label="Starts at" :value="$item?->starts_at?->format('Y-m-d\\TH:i')" placeholder="YYYY-MM-DDTHH:MM" />
        <x-ui.field name="ends_at" label="Ends at" :value="$item?->ends_at?->format('Y-m-d\\TH:i')" placeholder="YYYY-MM-DDTHH:MM" />
        <x-ui.field name="timezone" label="Timezone" :value="$item?->timezone ?? 'UTC'" />
        <x-ui.field name="jurisdiction" label="Jurisdiction" optional :value="$item?->jurisdiction" />
        <x-ui.field name="location" label="Location" optional :value="$item?->location" />
        <x-ui.field name="meeting_url" label="Meeting URL" optional :value="$item?->meeting_url" />
        <x-ui.field name="assignee_id" label="Assignee ID" optional :value="$item?->assignee_id" />
    </div>
    <x-ui.checkbox name="all_day" label="All day" :checked="$item?->all_day ?? false" />
    <x-ui.field name="reason" label="Reason for change" optional help="Required when changing a confirmed hard deadline." />

    <div class="mt-1 flex flex-wrap items-center gap-4">
        <x-ui.button variant="primary" type="submit">{{ $submitLabel }}</x-ui.button>
        <a href="{{ $cancelHref }}" class="text-[14.5px] text-vl-mut underline underline-offset-2 hover:text-vl-ink">Cancel</a>
    </div>
</form>
