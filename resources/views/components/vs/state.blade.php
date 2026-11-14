{{--
    <x-vs.state> — a state chip in the style guide's state language,
    NEVER renamed (spec 013, 03-contract.md §Component contract;
    design/STYLE_GUIDE.md §5; design/component-library.html §3).

    Props: state — one of the canonical states:
             established | satisfied   -> ok tone
             asserted | viable         -> cyan tone
             disputed | open           -> warn tone
             refuted | excluded        -> bad tone
             held | checkpoint         -> warn outline tone
           Matching is case-insensitive; underscores and spaces are
           accepted. An unrecognised state renders the neutral tone with
           the given text uppercased — fail-closed, never throws.

    The label text always renders (color is never the only signal). The
    slot, when given, replaces the derived label — for compound labels in
    the same language, e.g. a center-of-gravity marker.
--}}
@props([
    'state' => '',
])

@php
    $key = str_replace([' ', '_'], '-', strtolower(trim((string) $state)));
    $map = [
        'established' => ['vs-state--ok', 'ESTABLISHED'],
        'satisfied' => ['vs-state--ok', 'SATISFIED'],
        'asserted' => ['vs-state--cyan', 'ASSERTED'],
        'viable' => ['vs-state--cyan', 'VIABLE'],
        'disputed' => ['vs-state--warn', 'DISPUTED'],
        'open' => ['vs-state--warn', 'OPEN'],
        'refuted' => ['vs-state--bad', 'REFUTED'],
        'excluded' => ['vs-state--bad', 'EXCLUDED'],
        'held' => ['vs-state--warn-outline', 'HELD'],
        'held-by-user' => ['vs-state--warn-outline', 'HELD BY USER'],
        'checkpoint' => ['vs-state--warn-outline', 'CHECKPOINT'],
    ];
    [$toneClass, $label] = $map[$key] ?? ['vs-state--neutral', strtoupper((string) $state)];
@endphp

<span {{ $attributes->merge(['class' => 'vs-state ' . $toneClass]) }}>{{ $slot->isEmpty() ? $label : $slot }}</span>
