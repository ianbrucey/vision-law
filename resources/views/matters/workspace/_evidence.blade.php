{{-- Evidence pane — documents classified, tagged, provenance-labeled on arrival. --}}
<div>
    <div class="ga-pane-note">Every document classified, tagged, and analyzed on arrival — this metadata is what the agent queries when you say “the text thread.”</div>
    @foreach ($demo['evidence'] as $doc)
        <div class="ga-doc">
            <div class="ga-dic">{{ $doc['icon'] }}</div>
            <div>
                <b>{{ $doc['title'] }}</b>
                <small>{{ $doc['sub'] }}</small>
                <div class="ga-tags">
                    @foreach ($doc['tags'] as $tag)
                        <span class="ga-tag{{ ! empty($tag['hot']) ? ' ga-hot' : '' }}">{{ $tag['label'] }}</span>
                    @endforeach
                </div>
            </div>
            <span class="ga-pstat ga-{{ $doc['status_tone'] }}">● {{ $doc['status'] }}</span>
        </div>
    @endforeach
</div>
