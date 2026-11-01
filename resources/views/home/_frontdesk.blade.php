{{--
    home/_frontdesk.blade.php — Spec 012: the front-desk chat panel on the
    app home (Variant A of the chat-placement mockup; front-desk routing
    flow per design/mockups/front-desk-routing-mockup.html).

    A Glass/Aurora surface scoped under .ga, embedded in the spec 002 light
    home WITHOUT re-skinning it: this partial loads the workspace stylesheet
    itself, and every style lives in resources/css/workspace.css under .ga.
    Doors 2/3 exemption: recorded alongside the workspace exemption in
    docs/UI_Standards.md (same design system, same component library; door 1
    still applies — tokens only, no raw hex here).

    Front-end only, like Slice 1: the thread is a labeled sample, all state
    is in-page (resources/js/frontdesk.js), nothing is stored or sent. The
    ONE live binding is the matter-route card: $routeMatter is the user's
    first accessible matter, assembled by HomeController from the same
    Matter::accessibleBy scope as the rest of this page (read-only; no new
    query, no grant bypass). When the user has no accessible matter, the
    card degrades to sample text with no live link and no session state.

    "Open in workspace" performs the no-refresh handoff IN PLACE (plain DOM
    state, the passkey.js pattern): the header swaps to the matter, the
    position strip appears, the carried excerpt pins, and a sample
    in-matter message lands — all without leaving /home. "Open the full
    workspace" is the real route.

    Expects: $user, $routeMatter (Matter|null).
--}}
@vite(['resources/css/workspace.css', 'resources/js/frontdesk.js'])

@php
    $fdInitials = collect(explode(' ', (string) ($user->name ?? '')))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
        ->implode('');
@endphp

<section class="ga ga-frontdesk mb-6" data-ga-frontdesk aria-label="Front desk — agent chat preview">
    {{-- ============ State 1: the front desk (general chat) ============ --}}
    <div data-fd-state="desk">
        <div class="ga-fd-head">
            <span class="ga-avatar" aria-hidden="true">{{ $fdInitials === '' ? '?' : $fdInitials }}</span>
            <div class="ga-fd-titlewrap">
                <div class="ga-fd-title">Front desk</div>
                <div class="ga-fd-sub">Agent · all matters — your day, your calendar, any case</div>
            </div>
            <span class="ga-pill ga-ghost">General chat</span>
            <span class="ga-chip ga-chip-sample" title="The conversation in this preview is sample data">Sample preview</span>
        </div>

        <div class="ga-chat" data-fd-thread>
            <div class="ga-msg ga-agent">
                <div class="ga-who">Agent · Front desk</div>
                Morning. Ask me about any matter, your calendar, or what&rsquo;s waiting on you — or just tell me what&rsquo;s on your mind. Out here I can look across your cases, but I can&rsquo;t open one or change anything.
            </div>
            <div class="ga-msg ga-user">What do I have going on today?</div>
            <div class="ga-msg ga-agent">
                <div class="ga-who">Agent · Front desk</div>
                Two things on the calendar and one flag. Nothing needs a decision before noon.
            </div>

            <div class="ga-rollup">
                <span class="ga-rollup-k">Calendar · today</span>
                <b>10:00 AM</b> — Call: opposing counsel check-in<br>
                <b>4:30 PM</b> — Sunset Skate setup (personal)
            </div>

            @if ($routeMatter)
                <div class="ga-rollup ga-amber">
                    <span class="ga-rollup-k">Needs attention</span>
                    <b>{{ $routeMatter->title }}</b> — a checkpoint is waiting in its workspace: the gray-zone claim call.
                </div>

                <div class="ga-routecard" data-fd-routecard>
                    <div class="ga-routecard-lbl">Likely matter</div>
                    <div class="ga-routecard-t">{{ $routeMatter->title }}</div>
                    <div class="ga-routecard-s">{{ $routeMatter->matter_number }} · your most recently updated matter. Sample routing: the agent proposes the match — nothing opens, and nothing is written to the case, until you say so.</div>
                    <div class="ga-cp-btns">
                        <button type="button" class="ga-primary" data-fd-open>Open in workspace</button>
                        <button type="button" data-fd-dismiss>Not this one</button>
                    </div>
                </div>
            @else
                <div class="ga-rollup ga-amber">
                    <span class="ga-rollup-k">Needs attention</span>
                    A checkpoint is waiting in the sample workspace: the gray-zone claim call.
                </div>

                <div class="ga-routecard">
                    <div class="ga-routecard-lbl">Likely matter</div>
                    <div class="ga-routecard-t">Sample matter — wage claim</div>
                    <div class="ga-routecard-s">No matters are shared with you yet, so this card is a sample with no live link. When a matter matches, its title and number appear here with an &ldquo;Open in workspace&rdquo; button.</div>
                </div>
            @endif
        </div>

        <div class="ga-quickrow" style="margin-top: 10px">
            <button type="button" data-fd-chip="What&rsquo;s due this week?">What&rsquo;s due this week?</button>
            <button type="button" data-fd-chip="List my matters">List my matters</button>
            <button type="button" data-fd-chip="Anything waiting on me?">Anything waiting on me?</button>
        </div>
    </div>

    @if ($routeMatter)
        {{-- ============ State 2: in the matter, without leaving /home ============ --}}
        <div data-fd-state="session" hidden>
            <div class="ga-fd-head">
                <button type="button" class="ga-fd-backbtn" data-fd-back>&larr; Front desk</button>
                <div class="ga-fd-titlewrap">
                    <div class="ga-fd-title">{{ $routeMatter->title }}</div>
                    <div class="ga-fd-sub">{{ $routeMatter->matter_number }} · workspace session</div>
                </div>
                <span class="ga-pill ga-ok">In session</span>
            </div>

            <div class="ga-strip ga-fd-strip">
                <div class="ga-r1">
                    <span class="ga-pill">Assessment protocol</span>
                    <b>Stage 10 of 14</b>
                </div>
                <div class="ga-bar"><i style="width: 71%"></i></div>
                <div class="ga-r2">
                    <span><b>3</b> claims</span>
                    <span><b>34</b> facts</span>
                    <span><b>14</b> docs</span>
                    <span><b>3</b> open gaps</span>
                    <span class="ga-pill ga-amber">1 checkpoint waiting</span>
                </div>
            </div>

            <div class="ga-chat" data-fd-thread>
                <div class="ga-carried">
                    <div class="ga-carried-lbl">Brought from front desk · pinned intake note</div>
                    <em>&ldquo;What do I have going on today?&rdquo;</em> — plus the needs-attention flag on this matter.
                    <div class="ga-carried-note">Only this crossed over. The rest of the front-desk thread stays at the desk — that is the firewall.</div>
                </div>
                <div class="ga-sysmsg">You entered the workspace session · position loaded</div>
                <div class="ga-msg ga-agent">
                    <div class="ga-who">Agent · Assessment protocol</div>
                    I&rsquo;m in the case now — position loaded from the record, current stage is <b>Assessment</b>. In this sample: Claim 1 computes <b>strong — 0.78</b>, Claim 2 is <b>viable — 0.66</b> and hinges on willfulness, and the gray-zone checkpoint is still waiting on your call. The full record — battlefield, evidence, narrative, draft — is one tap below.
                </div>
                <a class="ga-fd-openfull" href="{{ route('matters.workspace', $routeMatter) }}">Open the full workspace &rarr;</a>
            </div>
        </div>
    @endif

    {{-- ============ Composer (shared by both states) ============ --}}
    <form class="ga-composer" data-fd-form>
        <label class="ga-iconbtn" title="Attach a document">
            &plus;
            <input type="file" class="ga-fileinput" data-fd-file>
        </label>
        <textarea rows="1" data-fd-input placeholder="Ask about any matter, or anything…" aria-label="Message the agent"></textarea>
        <button type="button" class="ga-iconbtn" disabled title="Voice input arrives in a later slice" aria-label="Voice input (arrives in a later slice)">🎙</button>
        <button type="submit" class="ga-send" aria-label="Send message">&uarr;</button>
    </form>
    <div class="ga-chat-note">UX preview — the agent is not connected yet, and nothing you type here is stored or sent. The conversation is a sample; the matter named on the card is a real matter you can access.</div>
</section>
