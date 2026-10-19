<x-layouts.app title="Deadline templates · Vision Law">
    <x-ui.page-header title="Deadline templates" subtitle="Versioned deadline rule templates for this organization. Published versions are immutable — edits fork a new draft, and publishing requires a second person (maker-checker).">
    </x-ui.page-header>

    <x-ui.card title="Search the catalog" class="mb-6">
        <form method="GET" action="{{ route('templates.deadlines.index') }}">
            <div class="grid gap-x-5 min-[821px]:grid-cols-2">
                <x-ui.field name="q" label="Name contains" :value="$q !== '' ? $q : null" optional />
                <x-ui.field name="jurisdiction" label="Jurisdiction contains" :value="$jurisdiction !== '' ? $jurisdiction : null" optional />
            </div>
            <x-ui.button variant="primary" type="submit">Search</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="New template" class="mb-6">
        <form method="POST" action="{{ route('templates.deadlines.store') }}">
            @csrf
            <x-ui.field name="name" label="Template name" required />
            <x-ui.field name="jurisdiction_label" label="Jurisdiction" optional />
            <x-ui.field name="owner_label" label="Content owner" optional help="Rule content owner of record. Demonstration content must stay labeled as such." />
            <x-ui.button variant="primary" type="submit">Create template</x-ui.button>
        </form>
    </x-ui.card>

    @if ($templates->isEmpty())
        <x-ui.empty title="No deadline templates yet">
            <p>Create the first template above, then add a draft version with its rules.</p>
        </x-ui.empty>
    @else
        <x-ui.table>
            <x-slot name="head">
                <tr>
                    <th>Name</th>
                    <th>Jurisdiction</th>
                    <th>Label</th>
                    <th>Owner</th>
                    <th>Current published</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </x-slot>
            <x-slot name="body">
                @foreach ($templates as $template)
                    <tr>
                        <td class="font-semibold">{{ $template->name }}</td>
                        <td>{{ $template->jurisdiction_label ?? '—' }}</td>
                        <td>
                            @if ($template->demonstration)
                                <x-ui.chip tone="warn">DEMONSTRATION</x-ui.chip>
                            @else
                                <x-ui.chip tone="neutral">Standard</x-ui.chip>
                            @endif
                        </td>
                        <td>{{ $template->owner_label ?? '—' }}</td>
                        <td>{{ $template->currentPublishedVersion ? 'v'.$template->currentPublishedVersion->version : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button size="sm" href="{{ route('templates.deadlines.show', $template) }}">View</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
    @endif
</x-layouts.app>
