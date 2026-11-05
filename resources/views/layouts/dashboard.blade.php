{{--
    layouts/dashboard.blade.php — the Spec 012 Glass/Aurora home shell.

    The redesigned app home (Ian's direction 2026-11-04): a sidebar menu,
    a top bar, the page slot, and the phone bottom nav (Chat / Matters /
    Today / More). Deliberately separate from layouts/app (spec 002 light
    theme): the dashboard is a dark Glass/Aurora surface per
    design/STYLE_GUIDE.md, its CSS (resources/css/workspace.css, .ga-dash
    section) is scoped under .ga and loaded ONLY here, and its JS
    (resources/js/dashboard.js) owns the shell interactions — sidebar
    collapse, the phone tab state — while frontdesk.js keeps the chat
    panel's own wiring.

    The sidebar carries the spec 010 C-05 chrome contract in GA form:
    the standard nav (Home, Matters, Calendar, Templates; Invitations +
    Retention for org admins), the user's name and organization,
    Security settings, and the Sign out form — so /home keeps every
    chrome guarantee the app layout gave it.

    Slots: content (required — the page).
    Props: title (string, default "Vision Law").
--}}
@php
    $dashUser = auth()->user();
    $dashOrg = $dashUser?->organization?->name;
    $dashIsAdmin = $dashUser?->hasRole('org_admin') ?? false;
    $dashInitials = collect(explode(' ', (string) ($dashUser->name ?? '')))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
        ->implode('');
    $dashNav = [
        ['Home', route('app.home'), '⌂', true],
        ['Matters', route('matters.index'), '▤', false],
        ['Calendar', route('calendar.index'), '◷', false],
        ['Templates', route('templates.index'), '▦', false],
    ];
    if ($dashIsAdmin) {
        $dashNav[] = ['Invitations', route('admin.invitations.index'), '✉', false];
        $dashNav[] = ['Retention', route('admin.retention.policies.index'), '▣', false];
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    @vite(['resources/css/workspace.css', 'resources/js/dashboard.js', 'resources/js/frontdesk.js'])
</head>
<body class="ga-body">
    <div class="ga ga-dash" data-ga-dash>
        <a href="#ga-dash-main" class="ga-dash-skip">Skip to main content</a>

        <div class="ga-dash-shell" data-ga-dash-shell>
            <aside class="ga-dash-side">
                <div class="ga-dash-brand">
                    <span class="ga-dash-mark" aria-hidden="true">V</span>
                    <span class="ga-dash-btxt">
                        <a class="ga-logo" href="{{ route('app.home') }}">VISION<span>LAW</span></a>
                        @if ($dashOrg)
                            <small>{{ $dashOrg }}</small>
                        @endif
                    </span>
                </div>

                <nav class="ga-dash-nav" aria-label="Primary">
                    @foreach ($dashNav as [$navLabel, $navHref, $navIcon, $navCurrent])
                        <a href="{{ $navHref }}" @if ($navCurrent) class="ga-on" aria-current="page" @endif>
                            <span class="ga-ic" aria-hidden="true">{{ $navIcon }}</span><span class="ga-dash-lbl">{{ $navLabel }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="ga-dash-note"><b>Front desk is read-only.</b><br>It reads across your matters and routes you in. Case work happens inside a matter.</div>

                <div class="ga-dash-sp"></div>

                <nav class="ga-dash-nav" aria-label="Account">
                    <a href="{{ route('two-factor.settings') }}">
                        <span class="ga-ic" aria-hidden="true">⚙</span><span class="ga-dash-lbl">Security settings</span>
                    </a>
                </nav>

                <div class="ga-dash-user">
                    <span class="ga-avatar" aria-hidden="true">{{ $dashInitials === '' ? '?' : $dashInitials }}</span>
                    <span class="ga-dash-utxt"><b>{{ $dashUser->name }}</b><small>{{ $dashIsAdmin ? 'Org admin' : 'Member' }}</small></span>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="ga-dash-signout">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>

                <button type="button" class="ga-dash-collapse" data-dash-collapse aria-label="Collapse sidebar">⟨ <span class="ga-dash-lbl">Collapse</span></button>
            </aside>

            <div class="ga-dash-main">
                <header class="ga-dash-topbar">
                    <a class="ga-logo ga-dash-phonebrand" href="{{ route('app.home') }}">VISION<span>LAW</span></a>
                    <div class="ga-dash-tt">
                        <span class="ga-dash-pagetitle">Home</span>
                        <span class="ga-dash-date">{{ fmtDate(now()) }}</span>
                    </div>
                    <div class="ga-dash-topright">
                        <span class="ga-avatar" aria-hidden="true">{{ $dashInitials === '' ? '?' : $dashInitials }}</span>
                    </div>
                </header>

                <main id="ga-dash-main" class="ga-dash-page">
                    {{ $content ?? $slot }}
                </main>

                {{-- Phone "More" panel: the sidebar's contents, reachable
                     from the bottom nav (the sidebar itself is desktop-only). --}}
                <section class="ga-dash-more" data-dash-panel="more" aria-label="More">
                    <div class="ga-dash-secttl">More</div>
                    <nav class="ga-dash-morelist" aria-label="More navigation">
                        @foreach ($dashNav as [$navLabel, $navHref, $navIcon, $navCurrent])
                            <a href="{{ $navHref }}"><span class="ga-ic" aria-hidden="true">{{ $navIcon }}</span>{{ $navLabel }}</a>
                        @endforeach
                        <a href="{{ route('two-factor.settings') }}"><span class="ga-ic" aria-hidden="true">⚙</span>Security settings</a>
                    </nav>
                    <div class="ga-dash-user">
                        <span class="ga-avatar" aria-hidden="true">{{ $dashInitials === '' ? '?' : $dashInitials }}</span>
                        <span class="ga-dash-utxt"><b>{{ $dashUser->name }}</b><small>{{ $dashOrg }}</small></span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="ga-dash-signout">
                        @csrf
                        <button type="submit">Sign out</button>
                    </form>
                </section>
            </div>
        </div>

        <nav class="ga-botnav ga-dash-botnav" aria-label="Home sections">
            <button type="button" data-dash-tab="chat" class="ga-on" aria-selected="true"><span class="ga-ic">💬</span>Chat</button>
            <button type="button" data-dash-tab="matters" aria-selected="false"><span class="ga-ic">▤</span>Matters</button>
            <button type="button" data-dash-tab="today" aria-selected="false"><span class="ga-ic">◷</span>Today</button>
            <button type="button" data-dash-tab="more" aria-selected="false"><span class="ga-ic">•••</span>More</button>
        </nav>
    </div>
</body>
</html>
