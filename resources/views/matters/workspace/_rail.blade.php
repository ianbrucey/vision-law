{{-- Position rail (desktop) — stage spine, active protocol, counts, gaps. --}}
<div class="ga-card ga-rail">
    <h3 class="ga-h3">Case Position — where are we?</h3>
    <div>
        @foreach ($demo['stages'] as $i => $stage)
            @php
                $stageState = $i < $demo['stage_index'] ? 'ga-done' : ($i === $demo['stage_index'] ? 'ga-now' : 'ga-todo');
            @endphp
            <div class="ga-stage {{ $stageState }}">
                <div class="ga-dot">{{ $i < $demo['stage_index'] ? '✓' : ($i === $demo['stage_index'] ? '●' : '') }}</div>
                {{ $stage }}
            </div>
        @endforeach
    </div>
    <div class="ga-proto">
        <div class="ga-pname">Active protocol: {{ strtoupper($demo['protocol']['name']) }}</div>
        <div class="ga-ptools">
            Tools in hand: {{ $demo['protocol']['in_hand'] }}<br>
            Tools withheld: {{ $demo['protocol']['withheld'] }}
        </div>
    </div>
    <div class="ga-counts">
        @foreach ($demo['counts'] as $count)
            <div class="ga-count"><b>{{ $count['value'] }}</b><span>{{ $count['label'] }}</span></div>
        @endforeach
    </div>
    <h3 class="ga-h3" style="margin-top: 14px">Open gaps — priced</h3>
    @foreach ($demo['gaps'] as $gap)
        <div class="ga-gapitem">{!! $gap !!}</div>
    @endforeach
</div>
