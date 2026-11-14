{{--
    <x-vs.band> — a characterization band (spec 013, 03-contract.md
    §Component contract; design/STYLE_GUIDE.md §5;
    design/component-library.html §3).

    Props: level (strong|viable|gray|failing; gray-zone and fail are
           accepted spellings of gray and failing). The canonical label
           renders by default — STRONG, VIABLE, GRAY ZONE, FAILING; the
           slot, when given, replaces it. Fail-closed: an unknown level
           renders as gray.
--}}
@props([
    'level' => 'gray',
])

@php
    $key = str_replace([' ', '_'], '-', strtolower(trim((string) $level)));
    $map = [
        'strong' => ['vs-band--strong', 'STRONG'],
        'viable' => ['vs-band--viable', 'VIABLE'],
        'gray' => ['vs-band--gray', 'GRAY ZONE'],
        'gray-zone' => ['vs-band--gray', 'GRAY ZONE'],
        'failing' => ['vs-band--failing', 'FAILING'],
        'fail' => ['vs-band--failing', 'FAILING'],
    ];
    [$levelClass, $label] = $map[$key] ?? ['vs-band--gray', strtoupper((string) $level)];
@endphp

<span {{ $attributes->merge(['class' => 'vs-band ' . $levelClass]) }}>{{ $slot->isEmpty() ? $label : $slot }}</span>
