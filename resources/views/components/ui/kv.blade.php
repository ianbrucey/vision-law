{{--
    <x-ui.kv> — label/value grid for reading a record (spec 002 03-contract.md).

    Props: items (array<array{label: string, value: string}>, required).
    Values are escaped by default (Blade {{ }}).
--}}
@props([
    'items' => null,
])

@php
    throw_unless(is_array($items), new \InvalidArgumentException('x-ui.kv requires "items" as an array of label/value pairs.'));
@endphp

<dl {{ $attributes->merge(['class' => 'grid grid-cols-[180px_1fr] gap-y-2.5 text-[14.5px] max-[820px]:grid-cols-1 max-[820px]:gap-y-0.5']) }}>
    @foreach ($items as $item)
        <dt class="text-vl-mut">{{ $item['label'] ?? '' }}</dt>
        <dd class="text-vl-text font-medium max-[820px]:mb-2">{{ $item['value'] ?? '' }}</dd>
    @endforeach
</dl>
