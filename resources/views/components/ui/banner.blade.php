{{--
    <x-ui.banner> — notice bands (spec 002 03-contract.md, mockup §06).

    Props: tone (info|success|warn|danger, required — warn = privilege/
           confidentiality notice, danger = destructive context),
           title (string|null, optional bold headline).

    Fail-closed: unknown tones render as info. Tone itself is required.
--}}
@props([
    'tone' => null,
    'title' => null,
])

@php
    throw_unless($tone, new \InvalidArgumentException('x-ui.banner requires a "tone" prop.'));

    $toneClasses = [
        'info' => 'bg-vl-info-soft border-vl-info',
        'success' => 'bg-vl-ok-soft border-vl-ok',
        'warn' => 'bg-vl-brass-soft border-vl-brass',
        'danger' => 'bg-vl-bad-soft border-vl-bad',
    ];
    // Fail-closed: unknown tones render as info.
    $toneClass = $toneClasses[$tone] ?? $toneClasses['info'];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-vl-control border px-[18px] py-[14px] text-[14.5px] text-vl-ink mb-3 ' . $toneClass]) }}>
    @if ($title)
        <strong class="block mb-0.5">{{ $title }}</strong>
    @endif
    {{ $slot }}
</div>
