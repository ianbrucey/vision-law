{{--    documents/create.blade.php — spec 007 T-10 (05-ui.md §02, mockup §02).

    One flow for single-shot and chunked uploads (007-D05): files ≤100 MiB
    POST straight to documents.upload; larger files drive the resumable
    8 MiB-chunk session protocol automatically (per-file progress rows,
    stage text, resume bitmap). Phone-first: the dropzone CTA and every
    row action are ≥44px.

    Expects: $matter, $folders (nested tree), $uploadConfig (urls + limits).
--}}
<x-layouts.app title="Add documents · {{ $matter->matter_number }} · Vision Law">
    <x-ui.page-header
        title="Add documents"
        subtitle="Single upload or resumable chunks — one flow"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.index', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
        </x-slot>
    </x-ui.page-header>

    @php
        // Flat id => indented-name list for the folder picker (index.blade.php pattern).
        $flatFolders = [];
        $flatten = function ($nodes, $prefix = '') use (&$flatten, &$flatFolders) {
            foreach ($nodes as $node) {
                $flatFolders[(string) $node->getKey()] = $prefix . $node->name;
                $flatten($node->children, $prefix . '— ');
            }
        };
        $flatten($folders);
    @endphp

    {{-- Dropzone (UI_Standards pattern: file dropzone). --}}
    <x-ui.card class="mb-4">
        <div id="dz-drop"
             class="rounded-vl-control border-2 border-dashed border-vl-line px-6 py-10 text-center transition-colors"
             role="button" tabindex="0" aria-label="Drop files here or choose files">
            <h2 class="font-serif text-[19px] text-vl-ink">Drop files here or</h2>
            <p class="mt-1 text-[14px] text-vl-mut">
                PDF, Word, Excel, PowerPoint, text, CSV, images, TIFF, MSG/EML — up to 100&nbsp;MB each.
                Larger files upload in resumable 8&nbsp;MB chunks automatically.
            </p>
            <x-ui.button id="dz-choose" variant="primary" class="mt-4 min-h-[44px]">Choose files</x-ui.button>
            {{-- Dropzone file picker: sr-only and driven by the dropzone/CTA (door 3 —
                 form controls only via x-ui.field; the native control lives in the
                 exempt components/ui/field.blade.php). --}}
            <div class="sr-only" aria-hidden="true">
                <x-ui.field label="Files" name="dz_files" id="dz-input" type="file" class="sr-only" multiple tabindex="-1" />
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <x-ui.select name="folder_id" id="dz-folder" label="Folder (optional)"
                         :options="['' => 'No folder'] + $flatFolders" />
            <x-ui.field name="tags" id="dz-tags" label="Tags (optional, comma-separated)" maxlength="500"
                        hint="Applied to every file in this batch." />
        </div>
    </x-ui.card>

    {{-- Per-file progress rows. --}}
    <div id="dz-rows" class="space-y-3" aria-live="polite"></div>

    <x-ui.banner tone="info" title="Every file is scanned before it's available" class="mt-4">
        Infected files go to quarantine and are never previewable. If the scanner is
        unreachable, uploads wait in <em>scanning</em> — nothing is ever silently marked clean.
    </x-ui.banner>

    <script>
        window.__uploadConfig = @json($uploadConfig);
    </script>
    @vite(['resources/js/document-upload.js'])
</x-layouts.app>
