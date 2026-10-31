{{-- Position strip (phone, Layout A) — protocol, stage, counts, checkpoint state. --}}
<div class="ga-strip">
    <div class="ga-r1">
        <span class="ga-pill">{{ strtoupper($demo['protocol']['name']) }} protocol</span>
        <b>Stage {{ $demo['stage_index'] + 1 }} of {{ count($demo['stages']) }}</b>
    </div>
    <div class="ga-bar"><i style="width: {{ (int) round(($demo['stage_index'] + 1) / count($demo['stages']) * 100) }}%"></i></div>
    <div class="ga-r2">
        @foreach ($demo['strip_counts'] as $count)
            <span><b>{{ $count['value'] }}</b> {{ $count['label'] }}</span>
        @endforeach
        <span style="color: var(--ga-warn)"><b style="color: var(--ga-warn)">{{ count($demo['gaps']) }}</b> open gaps</span>
        <span class="ga-pill ga-amber" x-show="cpChoice === null" x-cloak>1 checkpoint waiting</span>
        <span class="ga-pill ga-ok" x-show="cpChoice !== null" x-cloak>Checkpoint recorded ✓</span>
    </div>
</div>
