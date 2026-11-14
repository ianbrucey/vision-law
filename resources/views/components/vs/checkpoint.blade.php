{{--
    <x-vs.checkpoint> — the checkpoint card: a human-decision surface in
    the warn border family, header in warn, decision actions as buttons
    with the primary carrying the decision (spec 013, 03-contract.md
    §Component contract; design/component-library.html §8).

    Props: title (header text; defaults by state), state
           (awaiting|resolved, default awaiting — awaiting renders the
           warn family, resolved settles to a neutral surface with an ok
           header).
    Slots: the default slot is the decision body; the actions slot holds
           the decision buttons.
--}}
@props([
    'title' => null,
    'state' => 'awaiting',
])

@php
    $resolved = strtolower((string) $state) === 'resolved';
    $header = $title ?? ($resolved ? 'Checkpoint — resolved' : 'Checkpoint — your call required');
    $classes = 'vs-checkpoint ' . ($resolved ? 'vs-checkpoint--resolved' : 'vs-checkpoint--awaiting');
@endphp

<section {{ $attributes->merge(['class' => $classes]) }}>
    <div class="vs-checkpoint__head">{{ $resolved ? '✓' : '⚠' }} {{ $header }}</div>
    <div class="vs-checkpoint__body">{{ $slot }}</div>
    @isset($actions)
        <div class="vs-checkpoint__actions">{{ $actions }}</div>
    @endisset
</section>
