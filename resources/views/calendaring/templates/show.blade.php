<x-layouts.app :title="$template->name . ' · Vision Law'">
    <x-ui.page-header :title="$template->name" subtitle="Deadline template — versions are immutable once published; edits fork a new draft.">
        <x-slot name="crumbs">
            <a href="{{ route('templates.deadlines.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Deadline templates</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>{{ $template->name }}</span>
        </x-slot>
        <x-slot name="actions">
            @if ($template->demonstration)
                <x-ui.chip tone="warn">DEMONSTRATION</x-ui.chip>
            @endif
            @if ($currentPublished)
                <x-ui.chip tone="ok">Current published: v{{ $currentPublished->version }}</x-ui.chip>
            @endif
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Template">
        <x-ui.kv :items="[
            ['label' => 'Name', 'value' => $template->name],
            ['label' => 'Jurisdiction', 'value' => $template->jurisdiction_label ?? '—'],
            ['label' => 'Demonstration', 'value' => $template->demonstration ? 'Yes — demonstration content, not legal advice' : 'No'],
            ['label' => 'Owner', 'value' => $template->owner_label ?? '—'],
            ['label' => 'Current published', 'value' => $currentPublished ? 'v'.$currentPublished->version : '—'],
        ]" />
    </x-ui.card>

    <x-ui.card title="Versions" class="mt-6">
        @if ($template->versions->isEmpty())
            <p>No versions yet. Add the first draft below.</p>
        @else
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Version</th>
                        <th>Status</th>
                        <th>Maker</th>
                        <th>Checker</th>
                        <th>Published</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                    @foreach ($template->versions as $version)
                        <tr>
                            <td class="font-semibold">v{{ $version->version }}</td>
                            <td>
                                <x-ui.chip :tone="match($version->status) { 'published' => 'ok', 'draft' => 'warn', 'pending_review' => 'info', default => 'neutral' }">{{ ucfirst(str_replace('_', ' ', $version->status)) }}</x-ui.chip>
                            </td>
                            <td>{{ $version->creator?->name ?? '—' }}</td>
                            <td>{{ $version->reviewer?->name ?? '—' }}</td>
                            <td>{{ $version->published_at?->toDateString() ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex flex-wrap gap-2 justify-end">
                                    @if ($version->status === 'draft')
                                        <form method="POST" action="{{ route('templates.deadlines.versions.submit', [$template, $version]) }}">
                                            @csrf
                                            <x-ui.button size="sm" variant="primary" type="submit">Submit for review</x-ui.button>
                                        </form>
                                    @endif
                                    @if ($version->status === 'pending_review')
                                        <form method="POST" action="{{ route('templates.deadlines.versions.publish', [$template, $version]) }}">
                                            @csrf
                                            <x-ui.button size="sm" variant="primary" type="submit">Publish (checker)</x-ui.button>
                                        </form>
                                    @endif
                                    @if ($version->status === 'published')
                                        <form method="POST" action="{{ route('templates.deadlines.versions.retire', [$template, $version]) }}">
                                            @csrf
                                            <x-ui.button size="sm" variant="danger" type="submit">Retire</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="New draft version" class="mt-6">
        <p class="mb-4">Paste the rules JSON (engine format), or leave it empty to copy the current published version's rules. Rules are validated when saved — allowlisted operations only, and the rule graph must be acyclic.</p>
        @php
            $prefill = $currentPublished ? json_encode($currentPublished->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        @endphp
        <form method="POST" action="{{ route('templates.deadlines.versions.store', $template) }}">
            @csrf
            <x-ui.textarea name="rules" label="Rules (JSON)" :value="$prefill" rows="10" optional />
            <x-ui.button variant="primary" type="submit">Create draft version</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.app>
