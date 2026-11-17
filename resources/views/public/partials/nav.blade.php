{{--
    public/partials/nav.blade.php — Vision Systems landing nav (spec 013,
    03-contract.md §Landing page section contract §1).

    Brand = Vision Systems; the theme switcher mounts here; Sign in is a
    link, Request access is the primary action. Link labels and the CTA
    come from the fixtures (04-fixtures.json landing.nav); section links
    point at the in-page sections.

    Spec 010 (C-07, 010-D01): the header is auth-aware, preserved exactly
    from the previous nav. A signed-in visitor sees "Open app" (→ /home)
    and a Sign out form in place of Sign in / Request access; the guest
    rendering carries Sign in and no Open app. The landing itself stays
    public for everyone — no redirect (O-01). No user, org, or session
    data renders here (003 C-04).
--}}
@php
    $navHrefs = [
        'Platform' => '#platform',
        'Packs' => '#packs',
        'Trust' => '#trust',
        'Sign in' => '/login',
    ];
@endphp
<header class="vsl-header">
    <nav class="vsl-nav" aria-label="Site">
        <a href="/" class="vsl-brand">{{ $brand['name'] }}</a>
        <div class="vsl-nav-links">
            @foreach ($landing['nav']['links'] as $link)
                @if ($link === 'Sign in')
                    @guest
                        <a href="{{ $navHrefs[$link] ?? '#' }}">{{ $link }}</a>
                    @endguest
                @else
                    <a href="{{ $navHrefs[$link] ?? '#' }}">{{ $link }}</a>
                @endif
            @endforeach
        </div>
        <div class="vsl-nav-actions">
            <x-vs.theme-switcher :themes="$switcherThemes" />
            @auth
                <form method="POST" action="{{ route('logout') }}" class="vsl-signout-form">
                    @csrf
                    <x-vs.button type="submit" variant="ghost">Sign out</x-vs.button>
                </form>
                <x-vs.button variant="primary" href="{{ route('app.home') }}">Open app</x-vs.button>
            @else
                <x-vs.button variant="primary" href="#cta">{{ $landing['nav']['cta'] }}</x-vs.button>
            @endauth
        </div>
    </nav>
</header>
