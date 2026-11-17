{{--
    public/partials/platform.blade.php — the platform pipeline (spec
    013, 03-contract.md §Landing page section contract §3).

    The six steps from the fixtures (landing.platform.steps), each a
    card: step number, title, body — verbatim fixture copy. Every step
    names a shipped platform module or carries the fixtures' roadmap
    framing (013-D14 F1/F2).
--}}
@php
    $platform = $landing['platform'];
@endphp
<section id="platform" class="vsl-section">
    <div class="vsl-rise">
        <p class="vsl-eyebrow">{{ $landing['nav']['links'][0] }}</p>
        <h2 class="vsl-h2">{{ $platform['heading'] }}</h2>
    </div>
    <div class="vsl-steps">
        @foreach ($platform['steps'] as $step)
            <x-vs.card class="vsl-rise">
                <span class="vsl-step-n">{{ $step['n'] }}</span>
                <h3 class="vsl-step-title">{{ $step['title'] }}</h3>
                <p class="vsl-step-body">{{ $step['body'] }}</p>
            </x-vs.card>
        @endforeach
    </div>
</section>
