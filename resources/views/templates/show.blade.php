{{--
    templates/show.blade.php — spec 007 T-04 (007-D03).
    Expects: $template, $canPublish.
--}}
@php
    $tTitle = $template->name.' · Vision Law';
@endphp
<x-layouts.app :title="$tTitle">
    <x-ui.page-header :title="$template->name" :subtitle="'v' . $template->version . ' · created ' . $template->created_at->toDateString()">
        <x-slot name="crumbs">
            <a href="{{ route('templates.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Templates</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>{{ $template->name }}</span>
        </x-slot>
        <x-slot name="actions">
            <x-ui.status type="template" :value="$template->status" />
            <x-ui.button variant="secondary" href="{{ route('templates.edit', $template) }}" class="min-h-[44px]">Edit</x-ui.button>
            @if ($template->status === 'published')
                <x-ui.button variant="primary" href="{{ route('templates.generate.form', $template) }}" class="min-h-[44px]">Generate document</x-ui.button>
            @elseif ($canPublish)
                <form method="POST" action="{{ route('templates.publish', $template) }}" class="inline">
                    @csrf
                    <x-ui.button variant="primary" type="submit" class="min-h-[44px]">Publish</x-ui.button>
                </form>
            @endif
        </x-slot>
    </x-ui.page-header>

    @if ($template->status === 'draft' && ! $canPublish)
        <x-ui.banner tone="info" class="mb-4">This template is a draft. Publishing requires the template_editor role — ask an administrator.</x-ui.banner>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card>
            <h2 class="text-[15px] font-bold mb-2">Body</h2>
            <div class="border border-vl-line rounded-vl-control p-4 text-[15px] leading-relaxed">
                {!! $template->body_html !!}
            </div>
            <p class="text-[13px] text-vl-mut mt-2"><code>{{"{{merge_field}}"}}</code> placeholders fill at generation time and are HTML-escaped.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-[15px] font-bold mb-2">Merge fields</h2>
            @if (empty($template->fields()))
                <p class="text-vl-mut text-[14px]">No fields defined.</p>
            @else
                <x-ui.table>
                    <x-slot name="head">
                        <tr><th>Name</th><th>Type</th><th>Required</th><th>Source / default</th></tr>
                    </x-slot>
                <x-slot name="body">
                    @foreach ($template->fields() as $field)
                        <tr>
                            <td class="font-mono text-[13px]">{{ $field['name'] }}</td>
                            <td>{{ $field['type'] }}</td>
                            <td>{{ ($field['required'] ?? false) ? 'Yes' : 'No' }}</td>
                            <td class="text-[13px] text-vl-mut">{{ $field['source'] ?? ($field['default'] ?? '—') }}</td>
                        </tr>
                    @endforeach
                </x-slot>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
