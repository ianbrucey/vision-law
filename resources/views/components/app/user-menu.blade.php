{{--
    <x-app.user-menu> — the standard user menu (spec 010 C-05, C-06).

    Rendered by layouts/app as the default $userMenu content when a page
    does not furnish the slot. Contents: user name + organization,
    Security settings (route two-factor.settings), and the Sign out form
    (POST route logout — Fortify's backend, unchanged). Per 010-D02 there
    is deliberately no "Sessions" entry: no sessions page exists (001-D06 —
    sessions are a JSON-only API), and a menu item must not point at a JSON
    response.

    Contexts: "bar" (default) — an Alpine disclosure in the top bar;
    "drawer" — a static block inside the mobile nav drawer, so sign-out is
    reachable at phone width without opening the disclosure.
--}}
@props(['context' => 'bar'])
@auth
    @php
        $menuUser = auth()->user();
        $menuOrg = $menuUser->organization?->name;
    @endphp
    @if ($context === 'drawer')
        <div class="mt-6 pt-5 border-t border-vl-line">
            <p class="px-2.5 text-[15px] font-semibold text-vl-ink">{{ $menuUser->name }}</p>
            @if ($menuOrg)
                <p class="px-2.5 text-[13px] text-vl-mut mb-2">{{ $menuOrg }}</p>
            @endif
            <a href="{{ route('two-factor.settings') }}" class="block px-2.5 py-3 text-[15px] font-medium text-vl-ink no-underline rounded-vl-control hover:underline">Security settings</a>
            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <x-ui.button type="submit" variant="secondary" class="w-full justify-start">Sign out</x-ui.button>
            </form>
        </div>
    @else
        <div class="relative" x-data="{ menuOpen: false }">
            <x-ui.button variant="ghost" size="sm" class="min-h-[44px]" x-on:click="menuOpen = ! menuOpen" aria-haspopup="true" x-bind:aria-expanded="menuOpen.toString()">
                <span class="text-white max-w-[140px] truncate">{{ $menuUser->name }}</span>
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="text-white shrink-0"><path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-ui.button>
            <div x-show="menuOpen" x-cloak x-on:click.outside="menuOpen = false" x-on:keydown.escape.window="menuOpen = false" class="absolute right-0 top-full mt-2 w-64 rounded-vl-card bg-vl-card text-vl-text shadow-xl border border-vl-line p-2 z-50">
                <p class="px-2.5 pt-2 text-[15px] font-semibold text-vl-ink">{{ $menuUser->name }}</p>
                @if ($menuOrg)
                    <p class="px-2.5 text-[13px] text-vl-mut mb-1">{{ $menuOrg }}</p>
                @endif
                <a href="{{ route('two-factor.settings') }}" class="block px-2.5 py-3 text-[15px] font-medium text-vl-ink no-underline rounded-vl-control hover:underline">Security settings</a>
                <form method="POST" action="{{ route('logout') }}" class="mt-1">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" class="w-full justify-start">Sign out</x-ui.button>
                </form>
            </div>
        </div>
    @endif
@endauth
