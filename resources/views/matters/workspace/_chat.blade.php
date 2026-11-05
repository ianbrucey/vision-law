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
        <textarea x-model="draft" x-ref="composer" rows="1" x-on:input="growComposer()" x-on:keydown="composerKeydown($event)" placeholder="Message the agent…" aria-label="Message the agent"></textarea>
        <div class="ga-attachchips" x-show="attachments.length > 0" x-cloak>
            <template x-for="(name, index) in attachments" :key="index">
                <span class="ga-attachchip">📄 <span x-text="name"></span><button type="button" x-on:click="attachments.splice(index, 1)" aria-label="Remove attachment">✕</button></span>
            </template>
        </div>
        <div class="ga-composer-strip">
            <label class="ga-ghostbtn" title="Attach a document to this case">
                ＋
                <input type="file" class="ga-fileinput" x-on:change="attachSelected($event)">
            </label>
            <span class="ga-composer-hint">Enter &crarr; to send</span>
            <button type="button" class="ga-ghostbtn ga-acc" title="Speak instead of typing" aria-label="Speak instead of typing">🎙</button>
            <button type="submit" class="ga-sendtext" aria-label="Send message">↑</button>
        </div>
    </form>
</div>
