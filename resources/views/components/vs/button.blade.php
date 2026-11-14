{{--
    <x-vs.button> — Vision Systems action button (spec 013, 03-contract.md
    §Component contract; design/component-library.html §2).

    Props: variant (primary|secondary|danger|ghost, default secondary),
           type (button|submit|reset, default button, ignored when href
           present), href (string|null — renders an anchor styled as a
           button), disabled (bool).

    primary is the agent / actionable variant — one primary per view is a
    usage rule for callers, not something this component enforces.
    Fail-closed: an unknown variant falls back to secondary. Never throws.
--}}
@props([
    'variant' => 'secondary',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
])

@php
    $variantClass = [
        'primary' => 'vs-btn--primary',
        'secondary' => 'vs-btn--secondary',
        'danger' => 'vs-btn--danger',
        'ghost' => 'vs-btn--ghost',
    ][$variant] ?? 'vs-btn--secondary';
    $classes = 'vs-btn ' . $variantClass;
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($disabled) aria-disabled="true" @endif {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
