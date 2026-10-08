{{--
    templates/index.blade.php — spec 007 T-04 (DOC-22/23, 007-D03).
    Org template library: merge-field stationery, nothing intelligent.
    Expects: $templates, $canPublish.
--}}
<x-layouts.app title="Templates · Vision Law">
    <x-ui.page-header title="Templates" subtitle="Merge-field stationery — placeholders like @{{client_name}} fill from a form and the matter record.">
        <x-slot name="actions">
            <x-ui.button variant="primary" href="{{ route('templates.create') }}" class="min-h-[44px]">New template</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($templates->isEmpty())
        <x-ui.empty
            title="No templates yet"
            body="Create stationery once — letterhead, engagement letters, captions — and generate matter documents from it with merge fields." />
    @else
        <x-ui.card class="!p-0 overflow-hidden">
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Version</th>
                        <th>Fields</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                @foreach ($templates as $template)
                    <tr>
                        <td class="font-semibold">{{ $template->name }}</td>
                        <td><x-ui.status type="template" :value="$template->status" /></td>
                        <td>v{{ $template->version }}</td>
                        <td>{{ count($template->fields()) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button size="sm" href="{{ route('templates.show', $template) }}" class="min-h-[44px]">View</x-ui.button>
                            @if ($template->status === 'published')
                                <x-ui.button size="sm" href="{{ route('templates.generate.form', $template) }}" class="min-h-[44px]">Generate</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.app>
