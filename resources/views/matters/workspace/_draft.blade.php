{{-- Outline & Draft pane — locked draft state + the four verification passes. --}}
<div>
    <div class="ga-pane-note">🔒 Locked until computation completes — the outline is built in the simulation’s order, then drafted as structured blocks and rendered.</div>
    @foreach ($demo['outline'] as $node)
        <div class="ga-block">
            <div class="ga-bk">{{ $node['key'] }}</div>
            {!! $node['html'] !!}
        </div>
    @endforeach
    <h3 class="ga-h3" style="margin-top: 14px">Verification passes — required before any draft reaches you</h3>
    @foreach ($demo['passes'] as $pass)
        <div class="ga-pass">
            @if ($pass['done'])
                <span class="ga-pass-ok">✓</span>
            @else
                <span class="ga-pass-wait">…</span>
            @endif
            {{ $pass['label'] }}
        </div>
    @endforeach
</div>
