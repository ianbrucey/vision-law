{{--
    <x-vs.protocol> — the protocol box: an agent-owned surface with the
    dashed accent border signature, carrying the active protocol and its
    tools line (spec 013, 03-contract.md §Component contract;
    design/component-library.html §5).

    Props: name (the active protocol's name), tools (the tools-in-hand
           line), withheld (the tools-withheld line). The slot renders
           after the tools line for any additional protocol content.
--}}
@props([
    'name' => null,
    'tools' => null,
    'withheld' => null,
])

<section {{ $attributes->merge(['class' => 'vs-protocol']) }}>
    @if ($name)
        <div><b>Active protocol: {{ $name }}</b></div>
    @endif
    @if ($tools || $withheld)
        <div class="vs-protocol__tools">
            @if ($tools)
                <div>Tools in hand: {{ $tools }}</div>
            @endif
            @if ($withheld)
                <div>Tools withheld: {{ $withheld }}</div>
            @endif
        </div>
    @endif
    {{ $slot }}
</section>
