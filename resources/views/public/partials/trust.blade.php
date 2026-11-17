{{--
    public/partials/trust.blade.php — the trust section (spec 013,
    03-contract.md §Landing page section contract §5).

    Cards from the fixtures (landing.trust.items), verbatim. Claims are
    limited to what the shipped platform does — the export line is the
    fixtures' post-F4 text (original files, encrypted package, signed
    manifest; no contract-end deletion claim).
--}}
@php
    $trust = $landing['trust'];
@endphp
<section id="trust" class="vsl-section">
    <div class="vsl-rise">
        <p class="vsl-eyebrow">{{ $landing['nav']['links'][2] }}</p>
        <h2 class="vsl-h2">{{ $trust['heading'] }}</h2>
    </div>
    <div class="vsl-trust">
        @foreach ($trust['items'] as $item)
            <x-vs.card class="vsl-rise">
                <h3 class="vsl-step-title">{{ $item['title'] }}</h3>
                <p class="vsl-step-body">{{ $item['body'] }}</p>
            </x-vs.card>
        @endforeach
    </div>
</section>
