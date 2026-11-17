{{--
    layouts/public.blade.php — the public marketing + auth shell (spec 003 06-plan.md T-01).

    Minimal shell: <html lang>, meta viewport, title prop, @vite, skip link,
    toast mount (<x-ui.toast>), {{ $slot }}. No app topbar, no org/user slots
    (C-04 — public pages carry no auth session leakage).

    Interface for T-02..T-04 (component usage, same convention as
    <x-layouts.app>):  <x-layouts.public title="Page title"> …content… </x-layouts.public>
      Props: title (string, default "Vision Law") — the <title>.
             themed (bool, default false) — the Vision Systems themed
             surface (spec 013): the root carries data-vs-theme with the
             Ocean Deep default (013-D05), a pre-paint script restores a
             locally persisted theme (client-side only, 013-O06), the
             body takes the token surface (.vs-body), and an end-of-body
             script wires the theme switcher + entrance motion. Only the
             landing opts in; every other public page renders exactly as
             before.
      Slots: $slot (required) — the whole page (nav, sections, footer).

    003-D01 scope (documented per ticket): the dark cinematic treatment (tokens
    --vl-dark-0, --vl-dark-1, --vl-neon, --vl-neon-deep, --vl-dark-text,
    --vl-dark-mut) was a DELIBERATE, SCOPED EXCEPTION to the UI light-only door,
    applying only to the old marketing landing. Spec 013 supersedes it
    (013-D11): the rebuilt landing renders from the Vision Systems theme
    token sets via the themed mode above, and the --vl-dark-* tokens retire with
    the rebuild. This layout stays neutral so light and themed pages both
    sit on it unchanged. Everything behind auth stays light-only. Door 7
    (no Tailwind dark-mode variants, no dark class) is unchanged.
--}}
@props(['title' => 'Vision Law', 'themed' => false])
<!DOCTYPE html>
<html lang="en" @if ($themed) data-vs-theme="ocean-deep" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($themed)
        <script>
            document.documentElement.classList.add('vs-js');
            try {
                var vsStoredTheme = localStorage.getItem('vs-theme');
                if (vsStoredTheme) {
                    document.documentElement.setAttribute('data-vs-theme', vsStoredTheme);
                }
            } catch (e) {}
        </script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="{{ $themed ? 'vs-body antialiased' : 'bg-vl-paper text-vl-text font-sans antialiased' }}">
<div x-data>
    <a href="#vl-main" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:top-2 focus:left-2 focus:bg-vl-card focus:text-vl-ink focus:px-4 focus:py-2 focus:rounded-vl-control focus:border focus:border-vl-line">Skip to main content</a>

    <main id="vl-main">
        <x-ui.toast />
        {{ $slot }}
    </main>
</div>
@if ($themed)
    <script>
        (function () {
            var sel = document.getElementById('vs-theme-select');
            if (sel) {
                var current = document.documentElement.getAttribute('data-vs-theme');
                if (current) {
                    sel.value = current;
                }
                sel.addEventListener('change', function () {
                    document.documentElement.setAttribute('data-vs-theme', sel.value);
                    try {
                        localStorage.setItem('vs-theme', sel.value);
                    } catch (e) {}
                });
            }
            var risers = document.querySelectorAll('.vsl-rise');
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('vsl-in');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: .12 });
                risers.forEach(function (el) { io.observe(el); });
            } else {
                risers.forEach(function (el) { el.classList.add('vsl-in'); });
            }
        })();
    </script>
@endif
</body>
</html>
