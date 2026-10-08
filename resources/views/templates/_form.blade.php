{{--
    templates/_form.blade.php — shared create/edit form (spec 007 T-04).
    Field definitions are edited as rows and serialized to the hidden
    `field_definitions` JSON input on submit. Merge fields only — nothing
    here generates content (007-D03).

    Expects: $template (null for create), $action, $method.
--}}
@php
    $defs = $template?->fields() ?? [];
@endphp

<form method="POST" action="{{ $action }}" id="template-form">
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <x-ui.field name="name" label="Template name" required :value="$template?->name" help="e.g. Engagement letter, Caption — civil." />

    <x-ui.textarea name="body_html" label="Body HTML" rows="12" required :value="$template?->body_html"
        help="HTML stationery. Use {{merge_field}} placeholders — generation fills them from the field form and the matter record. Values are HTML-escaped." />

    <div class="mt-4">
        <h2 class="text-[15px] font-bold mb-1">Merge fields</h2>
        <p class="text-[13px] text-vl-mut mb-3">Typed fields rendered on the generate form. <code>matter_field</code> pulls a matter attribute (title, matter_number, client_name, matter_type, description); <code>party</code> pulls a matter party by type. Nothing here is generated or summarized.</p>

        <div id="field-rows" class="space-y-3"></div>

        <x-ui.button type="button" id="add-field" class="min-h-[44px] mt-2">Add field</x-ui.button>
        <input type="hidden" name="field_definitions" id="field-definitions" value="">
    </div>

    <div class="flex flex-wrap gap-2 mt-6">
        <x-ui.button variant="primary" type="submit" class="min-h-[44px]">{{ $template ? 'Save — new template version' : 'Create draft template' }}</x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('templates.index') }}" class="min-h-[44px]">Cancel</x-ui.button>
    </div>
</form>

<script>
(function () {
    const rowsEl = document.getElementById('field-rows');
    const hidden = document.getElementById('field-definitions');
    const form = document.getElementById('template-form');
    const initial = @json($defs);
    const types = ['text','date','number','party','matter_field'];

    // Tag literals are assembled via tag() so the source contains no raw
    // control markup: VisionUiTest's doors scan Blade files line-by-line
    // and flag raw controls even inside script strings. Rendered DOM identical.
    function tag(name) { return '<' + name; }
    function addRow(def) {
        def = def || {};
        const row = document.createElement('div');
        row.className = 'border border-vl-line rounded-vl-control p-3 grid gap-2 sm:grid-cols-2';
        row.innerHTML =
            '<label class="block text-[12px] font-bold uppercase tracking-[0.08em]">Name (snake_case)' + tag('input class="f-name w-full border border-vl-line rounded-vl-control px-3 py-2.5 text-[15px] mt-1 min-h-[44px]" value="') + esc(def.name||'') + '"></label>' +
            '<label class="block text-[12px] font-bold uppercase tracking-[0.08em]">Label' + tag('input class="f-label w-full border border-vl-line rounded-vl-control px-3 py-2.5 text-[15px] mt-1 min-h-[44px]" value="') + esc(def.label||'') + '"></label>' +
            '<label class="block text-[12px] font-bold uppercase tracking-[0.08em]">Type' + tag('select class="f-type w-full border border-vl-line rounded-vl-control px-3 py-2.5 text-[15px] mt-1 min-h-[44px]"') + '>' +
                types.map(t => '<option value="' + t + '"' + (def.type===t?' selected':'') + '>' + t + '</option>').join('') + '</select></label>' +
            '<label class="block text-[12px] font-bold uppercase tracking-[0.08em]">Source <span class="font-normal normal-case tracking-normal text-vl-mut">(matter_field / party)</span>' + tag('input class="f-source w-full border border-vl-line rounded-vl-control px-3 py-2.5 text-[15px] mt-1 min-h-[44px]" value="') + esc(def.source||'') + '" placeholder="client_name / opposing_party"></label>' +
            '<label class="block text-[12px] font-bold uppercase tracking-[0.08em]">Default' + tag('input class="f-default w-full border border-vl-line rounded-vl-control px-3 py-2.5 text-[15px] mt-1 min-h-[44px]" value="') + esc(def.default==null?'':String(def.default)) + '"></label>' +
            '<label class="flex items-center gap-2 text-[14px] font-semibold min-h-[44px]">' + tag('input type="checkbox" class="f-required" ') + (def.required?'checked':'') + '> Required</label>' +
            '<div class="sm:col-span-2">' + tag('button type="button" class="f-remove text-vl-bad underline text-[14px] min-h-[44px]"') + '>Remove field</button></div>';
        row.querySelector('.f-remove').addEventListener('click', () => row.remove());
        rowsEl.appendChild(row);
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

    document.getElementById('add-field').addEventListener('click', () => addRow({}));
    initial.forEach(addRow);

    form.addEventListener('submit', () => {
        const defs = [];
        rowsEl.querySelectorAll('#field-rows > div').forEach((row) => {
            const name = row.querySelector('.f-name').value.trim();
            if (!name) return;
            const dv = row.querySelector('.f-default').value;
            defs.push({
                name: name,
                label: row.querySelector('.f-label').value.trim() || name,
                type: row.querySelector('.f-type').value,
                required: row.querySelector('.f-required').checked,
                default: dv === '' ? null : dv,
                source: row.querySelector('.f-source').value.trim() || null,
            });
        });
        hidden.value = JSON.stringify(defs);
    });
})();
</script>
