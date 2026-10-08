{{--
    templates/generate.blade.php — spec 007 T-04 (DOC-22/23, 007-D03).
    Generate flow: field form rendered from the template's typed field
    definitions; matter_field / party values are pre-filled from the matter.
    Merge fields only — no drafting intelligence.

    Expects: $template, $matter, $prefilled (name => value), $defaultTitle,
    $genTitle, $genSubtitle.

    NOTE: Blade's component-tag parser chokes on complex expressions
    (array access with quotes, ternaries) inside bound :attributes, and a
    single bad tag silently corrupts the whole template's compilation.
    Every bound attribute below is a plain variable or literal — all
    expressions are precomputed in PHP blocks or the controller.
--}}
@php
    $tTitle = 'Generate from '.$template->name.' · Vision Law';
@endphp
<x-layouts.app :title="$tTitle">
    <x-ui.page-header :title="$genTitle" :subtitle="$genSubtitle" />

    <x-ui.card>
        <form method="POST" action="{{ route('templates.generate', $template) }}">
            @csrf
            <input type="hidden" name="matter_id" value="{{ $matter->getKey() }}">

            <x-ui.field name="title" label="Document title" required :value="$defaultTitle" />

            @forelse ($template->fields() as $field)
                @php
                    $fname = "fields[{$field['name']}]";
                    $fvalue = old("fields.{$field['name']}", $prefilled[$field['name']] ?? null);
                    $flabel = $field['label'] ?? ucwords(str_replace('_', ' ', (string) ($field['name'] ?? 'field')));
                    $ftype = $field['type'] ?? 'text';
                    $freq = $field['required'] ?? false;
                    $fhelp = in_array($ftype, ['matter_field', 'party'], true) ? 'Pre-filled from the matter record.' : null;
                @endphp
                @if ($ftype === 'date')
                    <x-ui.field :name="$fname" :label="$flabel" type="date" :value="$fvalue" :required="$freq" />
                @elseif ($ftype === 'number')
                    <x-ui.field :name="$fname" :label="$flabel" type="number" :value="$fvalue" :required="$freq" />
                @else
                    <x-ui.field :name="$fname" :label="$flabel" :value="$fvalue" :required="$freq" :help="$fhelp" />
                @endif
            @empty
                <p class="text-vl-mut text-[14px] mb-4">This template defines no merge fields — the body is used as-is.</p>
            @endforelse

            <div class="flex flex-wrap gap-2 mt-4">
                <x-ui.button variant="primary" type="submit" class="min-h-[44px]">Generate — publish v1</x-ui.button>
                <x-ui.button variant="secondary" href="{{ route('templates.show', $template) }}" class="min-h-[44px]">Cancel</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-layouts.app>
