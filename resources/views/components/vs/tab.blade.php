{{--
    <x-vs.tab> — a navigation tab; the selected tab is the accent surface
    (spec 013, 03-contract.md §Component contract;
    design/component-library.html §4).

    Props: href (string|null — renders an anchor; without it, a button),
           selected (bool, default false).
--}}
@props([
    'href' => null,
    'selected' => false,
])

@php
    $classes = 'vs-tab' . ($selected ? ' vs-tab--on' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($selected) aria-current="true" @endif {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="button" @if ($selected) aria-current="true" @endif {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
