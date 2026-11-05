{{--
    layouts/workspace.blade.php — the Spec 012 Glass/Aurora shell.

    Deliberately separate from layouts/app (spec 002 light theme): the
    Brain surface is dark-only per design/STYLE_GUIDE.md §8, and its CSS
    (resources/css/workspace.css) is scoped under .ga and loaded ONLY here,
    so no other screen changes. Loads resources/js/workspace.js BEFORE
    app.js so its alpine:init listener registers before Alpine starts.

    Slots: content (required — the page).
    Props: title (string, default "Vision Law").
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    @vite(['resources/css/workspace.css', 'resources/js/workspace.js', 'resources/js/voice.js', 'resources/js/app.js'])
</head>
<body class="ga-body">
    {{ $content ?? $slot }}
</body>
</html>
