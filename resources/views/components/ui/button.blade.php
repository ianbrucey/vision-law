{{--
    <x-ui.button> — the ONLY door for actions (docs/UI_Standards.md, spec 002 03-contract.md).

    Props: variant (primary|secondary|danger|ghost, default secondary), size (md|sm, default md),
           type (button|submit|reset, default button, ignored when href present),
           href (string|null, default null — renders <a> styled as a button), disabled (bool).

    Fail-closed: an unknown variant falls back to secondary. Render never throws.
--}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
])

@php
    $variantClasses = [
        'primary' => 'bg-vl-ink text-white border-transparent hover:bg-vl-ink-2',
        'secondary' => 'bg-vl-card text-vl-ink border-vl-line hover:border-vl-ink',
        'danger' => 'bg-vl-bad text-white border-transparent',
        'ghost' => 'bg-transparent text-vl-ink border-transparent hover:bg-vl-line',
    ];
    // Fail-closed: unknown variants render as secondary, never throw.
    $variantClass = $variantClasses[$variant] ?? $variantClasses['secondary'];
    $sizeClass = $size === 'sm'
        ? 'min-h-9 px-3.5 py-[7px] text-[13.5px]'
        : 'min-h-11 px-5 py-2.5 text-[15px]';
    $classes = 'inline-flex items-center justify-center gap-2 rounded-vl-control font-semibold '
        . 'border no-underline cursor-pointer disabled:opacity-45 disabled:cursor-not-allowed '
        . $sizeClass . ' ' . $variantClass;
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($disabled) aria-disabled="true" @endif {{ $attributes->merge(['class' => $classes . ($disabled ? ' pointer-events-none' : '')]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
