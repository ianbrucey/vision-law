{{--
    public/partials/cta.blade.php — the closing call to action (spec
    013, 03-contract.md §Landing page section contract §6).

    One primary action, no form in this spec — the CTA follows the
    existing site convention. Copy from the fixtures (landing.cta).
--}}
@php
    $cta = $landing['cta'];
@endphp
<section id="cta" class="vsl-section">
    <x-vs.card class="vsl-cta vsl-rise">
        <h2 class="vsl-h2">{{ $cta['heading'] }}</h2>
        <p class="vsl-lede">{{ $cta['body'] }}</p>
        <x-vs.button variant="primary" href="#">{{ $cta['button'] }}</x-vs.button>
    </x-vs.card>
</section>
