{{--
    <x-vs.stage-rail> — the ordered stage rail (spec 013, 03-contract.md
    §Component contract; design/component-library.html §5).

    Props: stages — a list of stage labels, or of arrays with a label key
             and an optional state key (done|now|upcoming);
           current — the zero-based index of the current stage, used to
             derive states when a stage does not carry its own.

    Completed stages show an ok check, the current stage an accent dot
    with glow, upcoming stages a muted empty dot. Position is legible at
    a glance; the current item carries aria-current="step".
--}}
@props([
    'stages' => [],
    'current' => null,
])

<ol {{ $attributes->merge(['class' => 'vs-rail']) }}>
    @foreach ($stages as $i => $stage)
        @php
            $label = is_array($stage) ? ($stage['label'] ?? '') : $stage;
            $state = is_array($stage) ? ($stage['state'] ?? null) : null;
            if ($state === null) {
                $state = $current === null ? 'upcoming' : ($i < $current ? 'done' : ($i === $current ? 'now' : 'upcoming'));
            }
            $stateClass = ['done' => 'vs-stage--done', 'now' => 'vs-stage--now', 'upcoming' => ''][$state] ?? '';
            $glyph = $state === 'done' ? '✓' : ($state === 'now' ? '●' : '');
        @endphp
        <li class="vs-stage {{ $stateClass }}" @if ($state === 'now') aria-current="step" @endif>
            <span class="vs-stage__dot" aria-hidden="true">{{ $glyph }}</span>{{ $label }}
        </li>
    @endforeach
</ol>
