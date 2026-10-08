{{--
    documents/preview.blade.php — spec 007 T-03 (05-ui.md §03, mockup §03).

    Phone-first preview: title bar, touch-sized toolbar (page nav, zoom,
    find), the document canvas, then metadata. PDF and converted Office /
    TIFF previews render through the locally-bundled pdf.js viewer
    (resources/js/preview-pdf.js — no CDN); images get zoom/rotate
    (resources/js/preview-image.js); text renders in an iframe.

    Expects: $matter, $document, $version, $previewMode
             (pdf|image|text|unavailable), $fileUrl, $downloadUrl,
             $pageCount, $metadata (extracted metadata array).
--}}
<x-layouts.app title="Preview · {{ $document->title }} · Vision Law">
    <x-ui.page-header
        title="Preview"
        :subtitle="'v' . $version->version_number . ($pageCount ? ' · ' . $pageCount . ' ' . \Illuminate\Support\Str::plural('page', $pageCount) : '')"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.index', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
        </x-slot>
        <x-slot name="actions">
            <x-ui.button variant="primary" :href="$downloadUrl" download>Download</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card class="mb-4">
        <h2 class="font-serif text-[19px] text-vl-ink leading-snug break-words">{{ $document->title }}</h2>
        <p class="text-vl-mut text-[13.5px] mt-1">
            {{ $version->original_filename ?? $document->title }}
            · {{ $document->kind }}
            @if ($document->metadata_status === 'partial')
                · <span class="font-semibold text-vl-warn">metadata partial</span>
            @endif
        </p>
    </x-ui.card>

    @if ($previewMode === 'pdf')
        {{-- PDF viewer (pdf.js, bundled locally). Toolbar: every target ≥44px. --}}
        <x-ui.card class="mb-4 !p-0 overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 p-3 border-b border-vl-line" role="toolbar" aria-label="Preview controls">
                <div class="flex items-center gap-1">
                    <x-ui.button id="pv-prev" aria-label="Previous page">‹</x-ui.button>
                    <span class="text-[14px] text-vl-mut px-1 whitespace-nowrap">
                        Page <input id="pv-page-input" type="number" min="1" value="1"
                            class="w-14 min-h-[44px] rounded-vl-control border border-vl-line bg-vl-card px-2 text-center text-vl-ink"
                            aria-label="Page number"> of <span id="pv-page-count">{{ $pageCount ?? '?' }}</span>
                    </span>
                    <x-ui.button id="pv-next" aria-label="Next page">›</x-ui.button>
                </div>
                <div class="flex items-center gap-1">
                    <x-ui.button id="pv-zoom-out" aria-label="Zoom out">−</x-ui.button>
                    <span id="pv-zoom-label" class="text-[14px] text-vl-mut w-14 text-center">100%</span>
                    <x-ui.button id="pv-zoom-in" aria-label="Zoom in">+</x-ui.button>
                </div>
                <div class="flex items-center gap-1 flex-1 min-w-[180px]">
                    <input id="pv-find" type="search" placeholder="Find in document"
                        class="flex-1 min-h-[44px] rounded-vl-control border border-vl-line bg-vl-card px-3 text-[15px] text-vl-ink placeholder:text-vl-mut"
                        aria-label="Find in document">
                    <x-ui.button id="pv-find-next" aria-label="Next match">↓</x-ui.button>
                </div>
                <x-ui.button id="pv-thumbs" variant="ghost" aria-pressed="true">Thumbnails</x-ui.button>
            </div>
            <div id="pv-find-status" class="hidden px-3 py-1.5 text-[13px] text-vl-mut border-b border-vl-line" role="status"></div>
            <div class="flex">
                <div id="pv-thumb-strip" class="w-28 shrink-0 max-h-[70vh] overflow-y-auto border-r border-vl-line p-2 space-y-2 hidden min-[821px]:block" aria-label="Page thumbnails"></div>
                <div id="pv-scroll" class="flex-1 max-h-[70vh] overflow-auto bg-vl-line/40 p-3">
                    <div id="pv-page-wrap" class="mx-auto relative bg-white shadow-sm" style="max-width:100%"></div>
                </div>
            </div>
            <p class="px-3 py-2 text-[12.5px] text-vl-mut border-t border-vl-line">Access re-checked on every page load. Text is selectable.</p>
        </x-ui.card>
        <script>
            window.__previewConfig = @json(['fileUrl' => $fileUrl, 'pageCount' => $pageCount]);
        </script>
        @vite(['resources/js/preview-pdf.js'])
    @elseif ($previewMode === 'image')
        {{-- Image viewer: zoom-to-fit / 100% / +, 90° rotate. --}}
        <x-ui.card class="mb-4 !p-0 overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 p-3 border-b border-vl-line" role="toolbar" aria-label="Image controls">
                <x-ui.button id="img-fit">Fit</x-ui.button>
                <x-ui.button id="img-100">100%</x-ui.button>
                <x-ui.button id="img-zoom-in" aria-label="Zoom in">+</x-ui.button>
                <x-ui.button id="img-zoom-out" aria-label="Zoom out">−</x-ui.button>
                <x-ui.button id="img-rotate" aria-label="Rotate 90 degrees">⟳</x-ui.button>
                <span id="img-zoom-label" class="text-[14px] text-vl-mut w-14 text-center">Fit</span>
            </div>
            <div id="img-scroll" class="max-h-[70vh] overflow-auto bg-vl-line/40 p-3 text-center">
                <img id="img-main" src="{{ $fileUrl }}&downscale=1600" data-full="{{ $fileUrl }}"
                    alt="Preview of {{ $document->title }}"
                    class="inline-block max-w-full shadow-sm" loading="eager" draggable="false">
            </div>
            <p class="px-3 py-2 text-[12.5px] text-vl-mut border-t border-vl-line">Showing a downscaled preview; zoom to 100% loads full resolution.</p>
        </x-ui.card>
        @vite(['resources/js/preview-image.js'])
    @elseif ($previewMode === 'text')
        <x-ui.card class="mb-4 !p-0 overflow-hidden">
            <iframe src="{{ $fileUrl }}" title="Text preview of {{ $document->title }}"
                class="w-full h-[70vh] bg-white" sandbox="allow-same-origin"></iframe>
        </x-ui.card>
    @else
        <x-ui.empty title="Preview unavailable" :actionHref="$downloadUrl" actionLabel="Download instead">
            This file type can&rsquo;t be previewed in the browser. Download it to view the original.
        </x-ui.empty>
    @endif

    {{-- Technical metadata (DOC-04) --}}
    @php
        $meta = is_array($metadata) ? $metadata : [];
        $props = $meta['properties'] ?? [];
        $kvItems = [
            ['label' => 'File', 'value' => $version->original_filename ?? $document->title],
            ['label' => 'Type', 'value' => $version->blob?->mime_sniffed ?? '—'],
            ['label' => 'Size', 'value' => $version->blob ? number_format($version->blob->size / 1024, 1) . ' KB' : '—'],
            ['label' => 'Version', 'value' => 'v' . $version->version_number],
        ];
        if (! empty($meta['width']) && ! empty($meta['height'])) {
            $dims = $meta['width'] . ' × ' . $meta['height'] . ' px';
            if (! empty($meta['dpi'])) { $dims .= ' · ' . $meta['dpi'] . ' DPI'; }
            $kvItems[] = ['label' => 'Dimensions', 'value' => $dims];
        }
        foreach (['title' => 'Title', 'creator' => 'Author', 'author' => 'Author', 'created' => 'Created', 'modified' => 'Modified', 'application' => 'Application', 'words' => 'Words', 'from' => 'From', 'subject' => 'Subject', 'date' => 'Date'] as $key => $label) {
            if (isset($props[$key]) && $props[$key] !== '') {
                $kvItems[] = ['label' => $label, 'value' => (string) $props[$key]];
            }
        }
        $kvItems[] = ['label' => 'Text layer', 'value' => ($meta['needs_ocr'] ?? false) ? 'Image-only — queued for OCR' : 'Native text'];
        $kvItems[] = ['label' => 'Metadata', 'value' => $document->metadata_status === 'partial' ? 'Partial — extraction incomplete, document still usable' : 'Complete'];
    @endphp
    <x-ui.card class="mb-4">
        <h2 class="font-serif text-[19px] text-vl-ink mb-3">Details</h2>
        <x-ui.kv :items="$kvItems" />
        @if (! empty($meta['notes']))
            <ul class="mt-3 text-[13px] text-vl-mut list-disc pl-5">
                @foreach ($meta['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</x-layouts.app>
