{{--
    shares/password.blade.php — spec 007 T-08 (DOC-25).

    Anonymous password gate for a protected share link. Deliberately
    minimal: no document title, filename, or metadata renders here — a
    wrong token never reaches this page (identical 404 instead).

    Expects: $locked (bool — 15-min lockout after 5 wrong attempts),
    $attemptsLeft (int), $error (string|null).
--}}
<x-layouts.public title="Protected link · Vision Law">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <x-ui.card title="This link is password protected" class="w-full max-w-[440px]">
            @if ($locked)
                <x-ui.banner tone="danger" title="Link locked">
                    Too many wrong attempts. Try again in 15 minutes.
                </x-ui.banner>
            @else
                @if (! empty($error))
                    <x-ui.banner tone="danger" title="Wrong password">
                        {{ $error }}
                        @if ($attemptsLeft > 0)
                            {{ $attemptsLeft }} {{ \Illuminate\Support\Str::plural('attempt', $attemptsLeft) }} left before a 15-minute lockout.
                        @endif
                    </x-ui.banner>
                @endif
                <form method="POST" action="{{ url()->current() }}">
                    @csrf
                    <x-ui.field
                        name="password"
                        label="Link password"
                        type="password"
                        required
                        autocomplete="current-password"
                    />
                    <x-ui.button variant="primary" type="submit" class="w-full">Open link</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
</x-layouts.public>
