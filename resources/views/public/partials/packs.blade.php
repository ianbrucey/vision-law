{{--
    public/partials/packs.blade.php — the vertical packs (spec 013,
    03-contract.md §Landing page section contract §4).

    Cards from the fixtures (landing.packs.list), Vision Law first and
    marked live. The status line renders the fixtures' status text
    verbatim, carried by a state chip in the style guide's language
    (live = established; in development = viable) or a quiet pill where
    no state in the language fits (the pack pattern itself).
--}}
@php
    $packs = $landing['packs'];
@endphp
<section id="packs" class="vsl-section">
    <div class="vsl-rise">
        <p class="vsl-eyebrow">{{ $landing['nav']['links'][1] }}</p>
        <h2 class="vsl-h2">{{ $packs['heading'] }}</h2>
        <p class="vsl-lede">{{ $packs['intro'] }}</p>
    </div>
    <div class="vsl-packs">
        @foreach ($packs['list'] as $pack)
            <x-vs.card class="vsl-rise">
                <x-vs.pill tone="ghost">{{ $pack['field'] }}</x-vs.pill>
                <h3 class="vsl-step-title">{{ $pack['name'] }}</h3>
                <p class="vsl-step-body">{{ $pack['body'] }}</p>
                <div class="vsl-pack-status">
                    @if ($pack['id'] === 'vision-law')
                        <x-vs.state state="established">{{ $pack['status'] }}</x-vs.state>
                    @elseif ($pack['id'] === 'permitting-code')
                        <x-vs.state state="viable">{{ $pack['status'] }}</x-vs.state>
                    @else
                        <x-vs.pill tone="ghost">{{ $pack['status'] }}</x-vs.pill>
                    @endif
                </div>
            </x-vs.card>
        @endforeach
    </div>
</section>
