{{--
    <x-ui.page-header> — every page starts here (spec 002 03-contract.md, mockup §04).

    Renders the page's SINGLE <h1> (30px Georgia, 26px at ≤820px via the
    h1.vl-page-title rule in resources/css/app.css).

    Props: title (string, required), subtitle (string|null).
    Named slots: crumbs (hierarchy), actions (button row), subnav (section tabs).
--}}
@props([
    'title' => null,
    'subtitle' => null,
])

@php
    throw_unless($title, new \InvalidArgumentException('x-ui.page-header requires a "title" prop.'));
@endphp

<div {{ $attributes->merge(['class' => 'mb-8']) }}>
    @isset($crumbs)
        <nav aria-label="Breadcrumb" class="text-[13px] text-vl-mut mb-2">{{ $crumbs }}</nav>
    @endisset
    <h1 class="vl-page-title text-vl-ink mb-1">{{ $title }}</h1>
    @if ($subtitle)
        <p class="text-[15px] text-vl-mut mb-3.5 max-w-[70ch]">{{ $subtitle }}</p>
    @endif
    @isset($actions)
        <div class="flex flex-wrap gap-2.5">{{ $actions }}</div>
    @endisset
    @isset($subnav)
        {{ $subnav }}
    @endisset
</div>
