{{--
    layouts/public.blade.php — the public marketing + auth shell (spec 003 06-plan.md T-01).

    Minimal shell: <html lang>, meta viewport, title prop, @vite, skip link,
    toast mount (<x-ui.toast>), {{ $slot }}. No app topbar, no org/user slots
    (C-04 — public pages carry no auth session leakage).

    Interface for T-02..T-04 (component usage, same convention as
    <x-layouts.app>):  <x-layouts.public title="Page title"> …content… </x-layouts.public>
      Props: title (string, default "Vision Law") — the <title>.
      Slots: $slot (required) — the whole page (nav, sections, footer).

    003-D01 scope (documented per ticket): the dark cinematic treatment (tokens
    --vl-dark-0, --vl-dark-1, --vl-neon, --vl-neon-deep, --vl-dark-text,
    --vl-dark-mut) is a DELIBERATE, SCOPED EXCEPTION to the UI light-only door.
    It applies ONLY to the marketing landing page (nav, hero, trust strip,
    final CTA, footer) — each dark section opts in explicitly in the page view.
    This layout stays neutral so light and dark pages both sit on it unchanged.
    Everything behind auth stays light-only. Door 7 (no Tailwind dark-mode variants, no
    dark class) is unchanged — the dark hero uses tokens, not dark-mode
    variants.
--}}
@props(['title' => 'Vision Law'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-vl-paper text-vl-text font-sans antialiased">
<div x-data>
    <a href="#vl-main" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:top-2 focus:left-2 focus:bg-vl-card focus:text-vl-ink focus:px-4 focus:py-2 focus:rounded-vl-control focus:border focus:border-vl-line">Skip to main content</a>

    <main id="vl-main">
        <x-ui.toast />
        {{ $slot }}
    </main>
</div>
</body>
</html>
