{{--
    <x-vs.count> — a count tile: a big value over a micro-label, the
    value in the text token and the label in the muted token (spec 013,
    03-contract.md §Component contract; design/component-library.html §6).

    Props: value (the count), label (the micro-label under it).
--}}
@props([
    'value' => '',
    'label' => '',
])

<div {{ $attributes->merge(['class' => 'vs-count']) }}>
    <span class="vs-count__value">{{ $value }}</span>
    <span class="vs-count__label">{{ $label }}</span>
</div>
