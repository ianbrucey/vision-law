{{--
    <x-ui.empty> — empty states (spec 002 03-contract.md, mockup §08).

    Props: title (string, required),
           actionHref / actionLabel (string|null — optional next-action link,
           rendered as a primary small button).
    Slot: the one reassuring sentence + what will make it non-empty.
    Never a blank area.
--}}
@props([
    'title' => null,
    'actionHref' => null,
    'actionLabel' => null,
])

@php
    throw_unless($title, new \InvalidArgumentException('x-ui.empty requires a "title" prop.'));
@endphp

<div {{ $attributes->merge(['class' => 'rounded-vl-card border-2 border-dashed border-vl-line px-6 py-10 text-center text-vl-mut mb-4']) }}>
    <h3 class="font-serif text-[19px] text-vl-ink mb-1.5">{{ $title }}</h3>
    <div class="text-[14.5px] mb-4">{{ $slot }}</div>
    @if ($actionHref && $actionLabel)
        <x-ui.button variant="primary" size="sm" :href="$actionHref">{{ $actionLabel }}</x-ui.button>
    @endif
</div>
