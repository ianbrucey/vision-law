{{--
    Agent chat column. Desktop: sticky right rail, always visible. Phone
    (Layout A): the Chat view, shown when tab === 'chat'. Messages render
    server-side from the sample transcript; anything the user does in-page
    (checkpoint decisions, quick replies, composer sends, attach picks) is
    appended from Alpine state ($extra) — nothing leaves the page in
    Slice 1.
--}}
<div class="ga-card ga-chatcol" :class="{ 'ga-mobile-on': tab === 'chat' }">
    <h3 class="ga-h3">Agent — working this case with you</h3>
    <div class="ga-chat" data-ga-chat-scroll>
        @foreach ($demo['messages'] as $message)
            @if ($message['kind'] === 'sys')
                <div class="ga-sysmsg">{!! $message['html'] !!}</div>
            @else
                <div class="ga-msg ga-{{ $message['kind'] }}">
                    @if ($message['who'] !== null)
                        <div class="ga-who">{{ $message['who'] }}</div>
                    @endif
                    {!! $message['html'] !!}
                </div>
            @endif
        @endforeach

        @include('matters.workspace._checkpoint')

        <template x-for="(message, index) in extra" :key="index">
            <div style="display: contents">
                <div class="ga-sysmsg" x-show="message.kind === 'sys'" x-html="message.html"></div>
                <div class="ga-msg" x-show="message.kind !== 'sys'" :class="'ga-' + message.kind">
                    <div class="ga-who" x-show="message.who" x-text="message.who"></div>
                    <span x-html="message.html"></span>
                </div>
            </div>
        </template>
    </div>

    <div class="ga-mobile-only">
        <div class="ga-sysmsg" style="margin-top: 10px">— or answer by voice —</div>
        <div class="ga-quickrow" style="margin-top: 8px">
            @foreach ($demo['quick_replies'] as $reply)
                <button type="button" x-on:click="quick(@js($reply))">🎙 {{ $reply }}</button>
            @endforeach
        </div>
    </div>

    <form class="ga-composer" x-on:submit.prevent="send()">
        <label class="ga-iconbtn" title="Attach a document to this case">
            ＋
            <input type="file" class="ga-fileinput" x-on:change="attachSelected($event)">
        </label>
        <input type="text" x-model="draft" placeholder="Message the agent…" aria-label="Message the agent">
        <button type="button" class="ga-iconbtn ga-acc" title="Speak instead of typing" aria-label="Speak instead of typing">🎙</button>
        <button type="submit" class="ga-send" aria-label="Send message">↑</button>
    </form>
    <div class="ga-chat-note">UX preview — the agent is not connected yet, and nothing you type here is stored or sent. In the finished workspace the agent works only inside the active protocol, on this case’s record; decisions at checkpoints are recorded with your name on them.</div>
</div>
