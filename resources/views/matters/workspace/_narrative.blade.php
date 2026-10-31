{{-- Narrative pane — verbatim client narrative + light orientation brief. --}}
<div>
    <div class="ga-pane-note" style="margin-bottom: 8px">Stored verbatim · provenance tier: narrative · never silently upgraded to “established”</div>
    <div class="ga-block">
        <div class="ga-bk">Client narrative — recorded at intake</div>
        {{ $demo['narrative']['client'] }}
    </div>
    <div class="ga-block">
        <div class="ga-bk">Orientation brief (light summary — auto-maintained)</div>
        {{ $demo['narrative']['brief'] }}
    </div>
</div>
