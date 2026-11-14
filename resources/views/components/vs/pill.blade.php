{{--
    <x-vs.pill> — a small rounded label whose tones map to signal tokens
    only (spec 013, 03-contract.md §Component contract;
    design/component-library.html §3).

    Props: tone (acc|ok|warn|bad|cyan|ghost, default ghost).
           acc is the solid agent tone; ghost is the quiet outline tone
           used for identifiers. Fail-closed: unknown tones render ghost.
--}}
@props([
    'tone' => 'ghost',
])

@php
    $toneClass = [
        'acc' => 'vs-pill--acc',
        'ok' => 'vs-pill--ok',
        'warn' => 'vs-pill--warn',
        'bad' => 'vs-pill--bad',
        'cyan' => 'vs-pill--cyan',
        'ghost' => 'vs-pill--ghost',
    ][$tone] ?? 'vs-pill--ghost';
@endphp

<span {{ $attributes->merge(['class' => 'vs-pill ' . $toneClass]) }}>{{ $slot }}</span>
