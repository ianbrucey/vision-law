{{--
    <x-ui.sub-nav> — section tabs under the page header (spec 002 03-contract.md,
    mockup §04/§09). v1 has no sidebars — this is the section nav.

    Props: items (array<array{label: string, href: string, active: bool}>, required).
--}}
@props([
    'items' => null,
])

@php
    throw_unless(is_array($items), new \InvalidArgumentException('x-ui.sub-nav requires "items" as an array of label/href/active rows.'));
@endphp

<nav aria-label="Sections" {{ $attributes->merge(['class' => 'flex gap-1 border-b border-vl-line mt-[18px]']) }}>
    @foreach ($items as $item)
        @php $subNavActive = ! empty($item['active']); @endphp
        <a
            href="{{ $item['href'] ?? '#' }}"
            @if ($subNavActive) aria-current="page" @endif
            class="px-4 py-2.5 no-underline text-[14px] font-semibold border-b-2 -mb-px {{ $subNavActive ? 'text-vl-ink border-vl-brass' : 'text-vl-mut border-transparent hover:text-vl-ink' }}"
        >{{ $item['label'] ?? '' }}</a>
    @endforeach
</nav>
