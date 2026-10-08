{{--
    matters/_form.blade.php — shared new/edit matter form (mockup §05).

    x-ui.field/select/textarea render inline errors from $errors with old()
    preserved (05-ui.md §States: validation). Cancellation is a link, never
    a button (docs/UI_Standards.md behavior law 7).

    Expects: $action (url), $httpMethod ('POST'|'PATCH'), $submitLabel,
    $typeOptions, $matter (Matter|null), $cancelHref.
--}}
<form method="POST" action="{{ $action }}">
    @csrf
    @if ($httpMethod !== 'POST')
        @method($httpMethod)
    @endif

    <x-ui.field
        name="title"
        label="Title"
        required
        placeholder="e.g. Sterling v. Apex Construction"
        :value="$matter?->title"
    />
    <div class="grid gap-x-5 min-[821px]:grid-cols-2">
        <x-ui.select
            name="matter_type"
            label="Matter type"
            :options="$typeOptions"
            placeholder="Select a type…"
            required
            :value="$matter?->matter_type"
        />
        <x-ui.field
            name="client_name"
            label="Client name"
            required
            placeholder="e.g. Sterling Manufacturing Co."
            :value="$matter?->client_name"
        />
    </div>
    <x-ui.textarea
        name="description"
        label="Description"
        optional
        rows="3"
        placeholder="What is this matter about?"
        :value="$matter?->description"
    />

    <div class="mt-1 flex flex-wrap items-center gap-4">
        <x-ui.button variant="primary" type="submit">{{ $submitLabel }}</x-ui.button>
        <a href="{{ $cancelHref }}" class="text-[14.5px] text-vl-mut underline underline-offset-2 hover:text-vl-ink">Cancel</a>
    </div>
</form>
