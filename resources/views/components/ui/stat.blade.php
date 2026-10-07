{{--
    <x-ui.stat> — big Georgia value over a 13px label, for matter dashboards
    (spec 002 03-contract.md, mockup §05). Rendered inside a card by the caller.
--}}
@props([
    'value' => null,
    'label' => null,
])

@php
    throw_unless($value !== null && $label !== null, new \InvalidArgumentException('x-ui.stat requires "value" and "label" props.'));
@endphp

<div {{ $attributes->merge(['class' => '']) }}>
    <div class="font-serif text-[34px] leading-tight text-vl-ink">{{ $value }}</div>
    <div class="text-[13px] text-vl-mut">{{ $label }}</div>
</div>
