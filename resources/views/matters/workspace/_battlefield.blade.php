{{-- Battlefield pane — mini graph + claim cards with element states. --}}
<div>
    <svg class="ga-graph" viewBox="0 0 640 190" aria-label="Battlefield mini graph" role="img">
        <rect class="ga-svg-box ga-svg-ok" x="4" y="70" width="104" height="46" rx="9"/><text class="ga-svg-t" x="16" y="90">GATES</text><text class="ga-svg-s" x="16" y="106">all pass</text>
        <path class="ga-svg-l" d="M108 93 H136"/>
        <rect class="ga-svg-box" x="136" y="14" width="196" height="40" rx="9"/><text class="ga-svg-t" x="148" y="38">Elements — Claim 1 (3)</text>
        <rect class="ga-svg-box ga-svg-warn" x="136" y="66" width="196" height="40" rx="9"/><text class="ga-svg-t" x="148" y="90">Elements — Claim 2 · ★ willfulness</text>
        <rect class="ga-svg-box" x="136" y="118" width="196" height="40" rx="9"/><text class="ga-svg-t" x="148" y="142">Third claim — HELD by you</text>
        <path class="ga-svg-l" d="M332 34 H356 M332 86 H356 M332 138 H356 M356 34 V138 M356 86 H380"/>
        <rect class="ga-svg-box ga-svg-acc" x="380" y="62" width="120" height="48" rx="9"/><text class="ga-svg-t" x="392" y="83">DISPOSITION</text><text class="ga-svg-s" x="392" y="99">computed ↓</text>
        <path class="ga-svg-l" d="M500 86 H528"/>
        <rect class="ga-svg-box" x="528" y="62" width="108" height="48" rx="9"/><text class="ga-svg-t" x="540" y="83">REMEDY $</text><text class="ga-svg-s" x="540" y="99">computed</text>
    </svg>

    @foreach ($demo['claims'] as $claim)
        <div class="ga-claimcard{{ $claim['cog'] ? ' ga-cog' : '' }}{{ $claim['dimmed'] ? ' ga-dim' : '' }}">
            <div class="ga-claimhead">
                <b>{{ $claim['title'] }}</b>
                <span class="ga-band ga-{{ $claim['band'] }}">{{ $claim['band_label'] }}</span>
                <div class="ga-score">{{ $claim['score_label'] }} <b>{{ $claim['score'] }}</b></div>
            </div>
            @foreach ($claim['elements'] as $element)
                <div class="ga-elrow">
                    <span class="ga-st ga-{{ $element['state'] }}">{{ $element['state_label'] }}</span>
                    <div>{{ $element['title'] }}<small>{{ $element['note'] }}</small></div>
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="ga-legend">Green = satisfied/established · Amber = disputed/open · ★ = the element whose loss kills the claim. The graph is a live view of the same record the agent works from — work it directly, or ask the agent.</div>
</div>
