{{--
    auth/two-factor-challenge.blade.php — 2FA challenge (spec 005 T-02).

    Guest view rendered only with a live challenged-user session: the GET
    two-factor-challenge route (named two-factor.login) applies Fortify's
    own guard ($request->hasChallengedUser()) and redirects to login
    otherwise. Two forms post to the existing POST two-factor.login.store
    backend, reused as-is (#s-challenge):

    - 6-digit TOTP code ("Verify and sign in").
    - recovery-code alternative ("Use recovery code").

    005-D05: one generic error message for every failure (bad TOTP, bad
    recovery code, consumed recovery code) — always keyed on `code`, so
    <x-ui.field> renders it under the code field and nothing about the
    failure is probeable. The account email is shown, not editable.
    Light-only (the 003-D01 dark-hero exception does not extend here).

    Spec 008: when the challenged user holds a passkey, a passkey section
    renders above the code form and takes the primary slot (the ceremony
    runs in passkey.js against PasskeyChallengeController). TOTP-only
    users ($hasPasskey false) get the 005 page exactly as before.
    Passkey-only users ($hasTotp false) get no code form — the recovery
    form remains as the break-glass (008-D02).
--}}
@php
    $hasPasskey = $hasPasskey ?? false;
    $hasTotp = $hasTotp ?? true;
@endphp
<x-layouts.public title="Two-factor authentication · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        <x-ui.card class="max-w-[420px] mx-auto">
            <div class="flex items-center justify-center w-16 h-16 rounded-2xl bg-vl-dark-0 mx-auto mb-[22px] overflow-hidden shadow-[0_0_24px_rgba(53,162,255,0.25)]">
                <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="w-[52px] h-auto block">
            </div>

            @if ($hasPasskey && ! $hasTotp)
                <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Verify it's you</h2>
                <p class="text-center text-vl-mut text-sm mb-7">
                    Use the passkey you set up for <strong class="text-vl-ink font-semibold">{{ $email }}</strong>.
                </p>
            @elseif ($hasPasskey)
                <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Two-factor authentication</h2>
                <p class="text-center text-vl-mut text-sm mb-7">
                    Use your passkey, or the 6-digit code from your authenticator app, for <strong class="text-vl-ink font-semibold">{{ $email }}</strong>.
                </p>
            @else
                <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Check your authenticator app</h2>
                <p class="text-center text-vl-mut text-sm mb-7">
                    Enter the 6-digit code for <strong class="text-vl-ink font-semibold">{{ $email }}</strong>.
                </p>
            @endif

            @if ($hasPasskey)
                {{-- Spec 008 passkey section — the primary action when present. --}}
                <x-ui.button variant="primary" type="button" id="passkey-assert" class="w-full">
                    Use a passkey
                </x-ui.button>
                <p class="text-[13px] text-vl-mut text-center mt-2">
                    Fingerprint, face, or device PIN — whatever this device offers.
                </p>

                <p id="passkey-unsupported" class="text-[13px] text-vl-mut text-center mt-3" hidden>
                    This browser doesn't support passkeys — use {{ $hasTotp ? 'the code from your app' : 'a recovery code' }} instead.
                </p>

                <p id="passkey-error" role="alert" class="text-[13px] text-vl-bad bg-vl-bad-soft border border-vl-bad rounded-[8px] px-3.5 py-2.5 mt-3" hidden></p>

                <div class="flex items-center gap-3 my-5" aria-hidden="true">
                    <span class="flex-1 border-t border-vl-line"></span>
                    <span class="text-[13px] text-vl-mut">or</span>
                    <span class="flex-1 border-t border-vl-line"></span>
                </div>
            @endif

            @if ($hasTotp)
                <form method="POST" action="{{ route('two-factor.login.store') }}">
                    @csrf

                    <x-ui.field
                        name="code"
                        label="6-digit code"
                        type="text"
                        required
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="······"
                        autocomplete="one-time-code"
                        class="text-center font-mono text-[18px] tracking-[0.3em]"
                    />

                    <x-ui.button variant="{{ $hasPasskey ? 'secondary' : 'primary' }}" type="submit" class="w-full mt-2">Verify and sign in</x-ui.button>
                </form>

                <div class="flex items-center gap-3 my-5" aria-hidden="true">
                    <span class="flex-1 border-t border-vl-line"></span>
                    <span class="text-[13px] text-vl-mut">or</span>
                    <span class="flex-1 border-t border-vl-line"></span>
                </div>
            @endif

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <x-ui.field
                    name="recovery_code"
                    label="Recovery code"
                    type="text"
                    required
                    value=""
                    placeholder="xxxx-xxxx"
                    autocomplete="off"
                    help="Lost your phone? A recovery code works once, then it's gone."
                    class="font-mono text-[16px] tracking-[0.1em]"
                />

                <x-ui.button variant="secondary" type="submit" class="w-full mt-2">Use recovery code</x-ui.button>
            </form>
        </x-ui.card>
    </div>

    @if ($hasPasskey)
        <script>
            window.__passkeyConfig = {
                csrf: @json(csrf_token()),
                assertOptionsUrl: @json(route('two-factor.passkey.options')),
                assertUrl: @json(route('two-factor.passkey.store')),
            };
        </script>
        @vite(['resources/js/passkey.js'])
    @endif
</x-layouts.public>
