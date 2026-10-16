{{--
    public/partials/nav.blade.php — marketing site nav (spec 003 06-plan.md T-02).

    Rendered inside <x-layouts.public> on the landing page only. Uses the
    003-D01 @theme tokens (vl-dark-0, vl-neon, vl-dark-text, vl-dark-mut) for
    the cinematic treatment — token utilities only, no raw hex, no inline
    styles. Links: in-page anchors where sections exist (#platform, #customers);
    "#" placeholders elsewhere, exactly as mocked. "Request a demo" stays a
    placeholder (non-goal); "Sign in" points at /login.

    Spec 010 (C-07, 010-D01): the header is auth-aware. A signed-in visitor
    sees "Open app" (→ /home) and a Sign out form in place of Sign in /
    Request a demo; the guest rendering is the 003 page unchanged. The
    landing itself stays public for everyone — no redirect (O-01).
--}}
<nav class="vl-public-nav bg-vl-dark-0 border-b border-[color-mix(in_srgb,var(--vl-neon)_18%,transparent)]" aria-label="Site">
    <div class="mx-auto max-w-[1120px] px-6 flex items-center gap-7 py-3.5">
        <a href="/" class="flex items-center gap-3 no-underline shrink-0">
            <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="h-9 w-auto block">
            <span class="font-serif text-[20px] text-white tracking-[0.01em] whitespace-nowrap">Vision Law</span>
        </a>
        <div class="hidden md:flex gap-[22px] ml-3">
            <a href="#platform" class="text-vl-dark-mut no-underline text-[14.5px] hover:text-white">Platform</a>
            <a href="#" class="text-vl-dark-mut no-underline text-[14.5px] hover:text-white">Security</a>
            <a href="#customers" class="text-vl-dark-mut no-underline text-[14.5px] hover:text-white">Customers</a>
            <a href="#" class="text-vl-dark-mut no-underline text-[14.5px] hover:text-white">Pricing</a>
        </div>
        <div class="ml-auto flex gap-3.5 items-center">
            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" class="vl-signout min-h-[44px]">Sign out</x-ui.button>
                </form>
                <a href="{{ route('app.home') }}" class="inline-flex items-center justify-center font-semibold no-underline rounded-[10px] border border-transparent min-h-[44px] text-white text-[14px] px-5 py-2.5 bg-[linear-gradient(180deg,var(--vl-neon),var(--vl-neon-deep))] shadow-[0_0_22px_color-mix(in_srgb,var(--vl-neon)_45%,transparent),inset_0_1px_0_color-mix(in_srgb,white_35%,transparent)]">Open app</a>
            @else
                <a href="/login" class="text-vl-dark-text no-underline text-[14.5px] font-semibold hover:text-white inline-flex items-center min-h-[44px]">Sign in</a>
                <a href="#" class="inline-flex items-center justify-center font-semibold no-underline rounded-[10px] border border-transparent min-h-[44px] text-white text-[14px] px-5 py-2.5 bg-[linear-gradient(180deg,var(--vl-neon),var(--vl-neon-deep))] shadow-[0_0_22px_color-mix(in_srgb,var(--vl-neon)_45%,transparent),inset_0_1px_0_color-mix(in_srgb,white_35%,transparent)]">Request a demo</a>
            @endauth
        </div>
    </div>
</nav>
