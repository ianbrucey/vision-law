{{--
    documents/version-diff.blade.php — spec 007 T-07 (DOC-21).

    Structured diff between two versions, phone-first unified rendering
    with page hints. Consecutive removed+added runs render as "changed"
    blocks. Image-only versions without extracted text get the clean
    "diff unavailable" state (no OCR here — T-06's lane).

    Expects: $matter, $document, $versionA, $versionB,
             $diff (DocumentVersioningService::diff() shape).
--}}
<x-layouts.app title="Diff v{{ $diff['from'] }} → v{{ $diff['to'] }} · {{ $document->title }} · Vision Law">
    <x-ui.page-header
        :title="'Diff v' . $diff['from'] . ' → v' . $diff['to']"
        :subtitle="$document->title"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.versions.index', [$matter->getKey(), $document->getKey()]) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">Versions</a>
        </x-slot>
    </x-ui.page-header>

    @if (! $diff['available'])
        <x-ui.empty title="Diff unavailable">
            @if ($diff['reason'] === 'no_text')
                These versions have no extracted text to compare (image-only content —
                text extraction runs in the processing pipeline).
            @elseif ($diff['reason'] === 'too_large')
                These versions are too large to diff in the browser.
            @else
                These versions are binary content and cannot be compared as text.
            @endif
        </x-ui.empty>
    @else
        <x-ui.card class="mb-4">
            <p class="text-[14px] text-vl-ink">
                <span class="font-semibold text-vl-ok">+{{ $diff['stats']['added'] }} added</span>
                ·
                <span class="font-semibold text-vl-bad">−{{ $diff['stats']['removed'] }} removed</span>
            </p>
            @if ($diff['pages'] === [])
                <p class="text-vl-mut text-[13.5px] mt-1">No differences — the two versions are text-identical.</p>
            @endif
        </x-ui.card>

        @foreach ($diff['pages'] as $page)
            <x-ui.card class="mb-4 !p-0 overflow-hidden">
                <div class="px-4 py-2.5 border-b border-vl-line bg-vl-card">
                    <h2 class="text-[13px] font-semibold uppercase tracking-wide text-vl-mut">Page {{ $page['page'] }}</h2>
                </div>
                @foreach ($page['hunks'] as $hunk)
                    <div class="overflow-x-auto">
                        <table class="w-full text-[13px] leading-relaxed font-mono">
                            <tbody>
                                @foreach ($hunk as $op)
                                    @php
                                        $rowClass = $op['type'] === 'add' ? 'bg-vl-ok-soft' : ($op['type'] === 'del' ? 'bg-vl-bad-soft' : '');
                                        $sign = $op['type'] === 'add' ? '+' : ($op['type'] === 'del' ? '−' : ' ');
                                    @endphp
                                    <tr class="{{ $rowClass }} border-b border-vl-line/50">
                                        <td class="w-10 shrink-0 px-2 py-1 text-right text-vl-mut select-none align-top">{{ $op['old'] ?? '' }}</td>
                                        <td class="w-10 shrink-0 px-2 py-1 text-right text-vl-mut select-none align-top">{{ $op['new'] ?? '' }}</td>
                                        <td class="w-6 shrink-0 px-1 py-1 text-center select-none align-top font-bold">{{ $sign }}</td>
                                        <td class="px-2 py-1 whitespace-pre-wrap break-words align-top">{{ $op['text'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (! $loop->last)
                        <div class="px-4 py-1.5 border-t border-vl-line text-vl-mut text-[12px]">⋮</div>
                    @endif
                @endforeach
            </x-ui.card>
        @endforeach
    @endif
</x-layouts.app>
