{{--
    matters/workspace.blade.php — Spec 012 Slice 1 (UX preview).

    The Glass/Aurora matter workspace: position rail + workspace tabs +
    agent chat (desktop three-column), collapsing to phone Layout A
    (position strip, one view at a time, bottom nav, Chat default).

    Renders the REAL matter's identity (title, number) in the header; the
    case content is the controller's demo data — no Brain records exist
    yet (build sequence steps 2–3). Rendered text is product copy only:
    no preview/sample commentary anywhere on the surface (Ian's standing
    rule, 2026-11-05). All interaction is in-page Alpine state
    (resources/js/workspace.js).
--}}
<x-layouts.workspace :title="$matter->title . ' · Workspace · Vision Law'">
    <div class="ga" x-data="gaWorkspace">
        <div class="ga-topbar">
            <a class="ga-backlink" href="{{ route('matters.show', $matter) }}" aria-label="Back to matter">‹</a>
            <a class="ga-logo" href="{{ route('app.home') }}">VISION<span>LAW</span></a>
            <div class="ga-matterline">
                Matter <b>{{ $matter->title }}</b> · {{ $matter->matter_number }} · <b style="color: var(--ga-warn)">Work product — privileged</b>
            </div>
            <div class="ga-topright">
                <a class="ga-chip ga-fd-navchip" href="{{ route('app.home') }}" title="Back to the front desk on Home">&larr; Front desk</a>
                <span class="ga-chip ga-chip-posture">Posture: <b>{{ $demo['posture'] }}</b></span>
                <span class="ga-chip ga-chip-mode">Mode: <b>{{ $demo['mode'] }}</b></span>
                <span class="ga-avatar" aria-hidden="true">{{ $initials }}</span>
            </div>
        </div>

        @include('matters.workspace._strip')

        <div class="ga-shell">
            @include('matters.workspace._rail')

            <div class="ga-card ga-maincard" :class="{ 'ga-mobile-off': tab === 'chat' }">
                <div class="ga-tabs" role="tablist" aria-label="Workspace">
                    <button type="button" class="ga-tab" :class="{ 'ga-on': tab === 'battlefield' || tab === 'chat' }" x-on:click="show('battlefield')">Battlefield</button>
                    <button type="button" class="ga-tab" :class="{ 'ga-on': tab === 'evidence' }" x-on:click="show('evidence')">Evidence</button>
                    <button type="button" class="ga-tab" :class="{ 'ga-on': tab === 'narrative' }" x-on:click="show('narrative')">Narrative</button>
                    <button type="button" class="ga-tab" :class="{ 'ga-on': tab === 'draft' }" x-on:click="show('draft')">Outline &amp; Draft</button>
                </div>

                <div x-show="tab === 'battlefield' || tab === 'chat'">
                    @include('matters.workspace._battlefield')
                    <div class="ga-mobile-only" style="margin-top: 14px">
                        <div class="ga-secttl">Narrative</div>
                        @include('matters.workspace._narrative')
                    </div>
                </div>
                <div x-show="tab === 'evidence'" x-cloak>
                    @include('matters.workspace._evidence')
                </div>
                <div x-show="tab === 'narrative'" x-cloak>
                    @include('matters.workspace._narrative')
                </div>
                <div x-show="tab === 'draft'" x-cloak>
                    @include('matters.workspace._draft')
                </div>
            </div>

            @include('matters.workspace._chat')
        </div>

        <nav class="ga-botnav" aria-label="Workspace">
            <button type="button" :class="{ 'ga-on': tab === 'chat' }" x-on:click="show('chat')"><span class="ga-ic">💬</span>Chat</button>
            <button type="button" :class="{ 'ga-on': tab === 'battlefield' }" x-on:click="show('battlefield')"><span class="ga-ic">▦</span>Record</button>
            <button type="button" :class="{ 'ga-on': tab === 'evidence' }" x-on:click="show('evidence')"><span class="ga-ic">🗂</span>Evidence</button>
            <button type="button" :class="{ 'ga-on': tab === 'draft' }" x-on:click="show('draft')"><span class="ga-ic">✎</span>Draft</button>
        </nav>
    </div>
</x-layouts.workspace>
