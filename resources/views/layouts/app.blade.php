{{--
    layouts/app.blade.php — the app shell (spec 002 03-contract.md §Layout,
    mockup §09).

    Fixed top bar (wordmark "Vision Law", org slot, user-menu slot), Alpine
    mobile nav drawer, content max-width 1120px, toast mount point, skip link.

    Slots: content (required — the page),
           header (optional block above main, e.g. for <x-ui.page-header>),
           nav (primary nav links — inline on desktop, drawer on phones),
           org (org switcher), userMenu (user menu),
           footer (optional footer link row).
    Props: title (string, default "Vision Law") — the <title>.

    The whole page lives inside one x-data scope: that is what makes Alpine
    triggers (modal open buttons, drawer toggles) work anywhere in the page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-vl-paper text-vl-text font-sans antialiased">
<div x-data="{ drawerOpen: false }">
    <a href="#vl-main" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:top-2 focus:left-2 focus:bg-vl-card focus:text-vl-ink focus:px-4 focus:py-2 focus:rounded-vl-control focus:border focus:border-vl-line">Skip to main content</a>

    {{-- Fixed top bar --}}
    <header class="vl-topbar fixed inset-x-0 top-0 z-40 bg-vl-ink text-white">
        <div class="mx-auto max-w-[1120px] px-4 min-[821px]:px-8 h-16 flex items-center gap-3">
            <x-ui.button variant="ghost" size="sm" aria-label="Open navigation" class="vl-bar-toggle min-[821px]:hidden shrink-0" x-on:click="drawerOpen = true">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </x-ui.button>
            <span class="font-serif text-[17px] tracking-[0.02em] shrink-0">Vision Law</span>
            @isset($org)
                <span class="text-[13px] text-white/70 hidden min-[821px]:inline">{{ $org }}</span>
            @endisset
            @isset($nav)
                <nav aria-label="Primary" class="hidden min-[821px]:flex items-center gap-1 ml-2">{{ $nav }}</nav>
            @endisset
            <div class="ml-auto text-[13px] text-white/80 flex items-center gap-3">
                @isset($userMenu){{ $userMenu }}@endisset
            </div>
        </div>
    </header>

    {{-- Mobile nav drawer (Alpine; keyboard-operable, Escape closes, focus trapped) --}}
    <div x-show="drawerOpen" x-cloak class="fixed inset-0 z-50 min-[821px]:hidden" x-on:keydown.escape.window="drawerOpen = false">
        <div class="absolute inset-0 bg-vl-ink/45" x-on:click="drawerOpen = false" aria-hidden="true"></div>
        <aside class="vl-drawer absolute inset-y-0 left-0 w-[280px] max-w-[85vw] bg-vl-card p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-label="Site navigation" x-trap="drawerOpen">
            <div class="flex items-center justify-between mb-4">
                <span class="font-serif text-[17px] text-vl-ink">Vision Law</span>
                <x-ui.button variant="ghost" size="sm" aria-label="Close navigation" x-on:click="drawerOpen = false">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </x-ui.button>
            </div>
            @isset($nav)
                <nav aria-label="Primary">{{ $nav }}</nav>
            @endisset
        </aside>
    </div>

    {{-- Content (offset for the fixed top bar) --}}
    <div class="pt-16">
        <div class="mx-auto max-w-[1120px] px-4 min-[821px]:px-8 py-8">
            @isset($header)
                {{ $header }}
            @endisset
            <main id="vl-main">
                <x-ui.toast />
                {{ $content ?? $slot }}
            </main>
            @isset($footer)
                <footer class="mt-8 pt-6 border-t border-vl-line text-[13px] text-vl-mut flex flex-wrap gap-x-6 gap-y-2">{{ $footer }}</footer>
            @endisset
        </div>
    </div>
</div>
</body>
</html>
