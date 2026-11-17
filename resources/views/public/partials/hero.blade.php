{{--
    public/partials/hero.blade.php — Vision Systems landing hero (spec
    013, 03-contract.md §Landing page section contract §2; 05-ui.md
    §Layout).

    Copy left, component vignette right at >=820px; stacked at phone
    widths. All copy comes from the fixtures (landing.hero; the vignette
    renders platform.sample_*). The vignette is a product illustration
    composed from <x-vs.*> components: the pipeline's first stages on
    the stage rail, the sample evidence rows, and the sample count
    tiles — with the fixtures' illustration label rendered visibly at
    its head (013-D14 F3: sample numbers never appear unlabeled).
--}}
@php
    $hero = $landing['hero'];
    $platform = $landing['platform'];
    $railStages = array_column(array_slice($platform['steps'], 0, 4), 'title');
@endphp
<section class="vsl-hero">
    <div class="vsl-rise">
        <p class="vsl-eyebrow">{{ $hero['eyebrow'] }}</p>
        <h1 class="vsl-h1">{{ $hero['headline'] }}</h1>
        <p class="vsl-lede">{{ $hero['sub'] }}</p>
        <div class="vsl-cta-row">
            <x-vs.button variant="primary" href="#cta">{{ $hero['primary_cta'] }}</x-vs.button>
            <x-vs.button variant="secondary" href="#platform">{{ $hero['secondary_cta'] }}</x-vs.button>
        </div>
        <p class="vsl-note"><span class="vsl-note-dot" aria-hidden="true"></span>{{ $hero['note'] }}</p>
    </div>
    <div class="vsl-rise">
        <x-vs.card class="vsl-vignette" aria-label="Platform workspace illustration">
            <div class="vsl-vignette-head">
                <x-vs.pill tone="ghost">{{ $platform['sample_label'] }}</x-vs.pill>
            </div>
            <x-vs.stage-rail :stages="$railStages" :current="2" />
            @foreach ($platform['sample_evidence_rows'] as $row)
                <x-vs.evidence-row
                    :title="$row['doc']"
                    :meta="$row['meta']"
                    :anchor="$row['anchor']"
                    :flagged="str_contains($row['meta'], 'flagged')"
                />
            @endforeach
            <div class="vsl-counts">
                @foreach ($platform['sample_counts'] as $count)
                    <x-vs.count :value="$count['value']" :label="$count['label']" />
                @endforeach
            </div>
        </x-vs.card>
    </div>
</section>
