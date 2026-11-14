{{--
    <x-vs.evidence-row> — an evidence row: document icon, title, meta
    line, page-anchor chip, and tags riding the row (spec 013,
    03-contract.md §Component contract; design/component-library.html §9).

    Props: title (document title), meta (secondary line), anchor (page
           anchor, e.g. a page number label), ext (file-type letters for
           the icon tile), tags (a list of tag labels, or of arrays with
           a label key and an optional tone key — the warn tone marks a
           low-confidence tag), flagged (bool — the row adopts the warn
           border family and, when a flag label is shown, a warn tag),
           flagLabel (the flag tag's text, default "Low confidence";
           shown only when flagged).
--}}
@props([
    'title' => '',
    'meta' => null,
    'anchor' => null,
    'ext' => null,
    'tags' => [],
    'flagged' => false,
    'flagLabel' => 'Low confidence',
])

@php
    $classes = 'vs-evidence' . ($flagged ? ' vs-evidence--flagged' : '');
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    @if ($ext)
        <div class="vs-evidence__icon" aria-hidden="true">{{ $ext }}</div>
    @endif
    <div class="vs-evidence__main">
        <div class="vs-evidence__title">{{ $title }}</div>
        @if ($meta)
            <div class="vs-evidence__meta">{{ $meta }}</div>
        @endif
        @if ($flagged || $tags)
            <div class="vs-evidence__tags">
                @foreach ($tags as $tag)
                    @php
                        $tagLabel = is_array($tag) ? ($tag['label'] ?? '') : $tag;
                        $tagTone = is_array($tag) ? ($tag['tone'] ?? null) : null;
                    @endphp
                    <span class="vs-tag{{ $tagTone === 'warn' ? ' vs-tag--warn' : '' }}">{{ $tagLabel }}</span>
                @endforeach
                @if ($flagged && $flagLabel)
                    <span class="vs-tag vs-tag--warn">{{ $flagLabel }}</span>
                @endif
            </div>
        @endif
        {{ $slot }}
    </div>
    @if ($anchor)
        <span class="vs-evidence__anchor">{{ $anchor }}</span>
    @endif
</div>
