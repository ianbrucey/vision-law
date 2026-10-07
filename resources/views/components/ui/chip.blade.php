{{--
    <x-ui.chip> — small metadata labels (spec 002 03-contract.md).

    Props: tone (ok|warn|bad|neutral|info, default neutral).
    Fail-closed: unknown tones render neutral. Never the sole signal for a status.

    Note: <x-ui.status> delegates to this component; the tone class map below is
    the single source of chip styling.
--}}
@props([
    'tone' => 'neutral',
])

@php
    $toneClasses = [
        'ok' => 'bg-vl-ok-soft text-vl-ok',
        'warn' => 'bg-vl-brass-soft text-vl-brass',
        'bad' => 'bg-vl-bad-soft text-vl-bad',
        'neutral' => 'bg-vl-paper text-vl-mut border border-vl-line',
        'info' => 'bg-vl-info-soft text-vl-info',
    ];
    // Fail-closed: unknown tones render neutral.
    $toneClass = $toneClasses[$tone] ?? $toneClasses['neutral'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-vl-chip text-[12.5px] font-bold px-3 py-[3px] tracking-[0.02em] ' . $toneClass]) }}>{{ $slot }}</span>
