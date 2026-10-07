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
--}}
<x-layouts.public title="Two-factor authentication · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        <x-ui.card class="max-w-[420px] mx-auto">
            <div class="flex items-center justify-center w-16 h-16 rounded-2xl bg-vl-dark-0 mx-auto mb-[22px] overflow-hidden shadow-[0_0_24px_rgba(53,162,255,0.25)]">
                <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="w-[52px] h-auto block">
            </div>

            <h2 class="text-[24px] text-center text-vl-ink font-semibold mb-1.5">Check your authenticator app</h2>
            <p class="text-center text-vl-mut text-sm mb-7">
                Enter the 6-digit code for <strong class="text-vl-ink font-semibold">{{ $email }}</strong>.
            </p>

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

                <x-ui.button variant="primary" type="submit" class="w-full mt-2">Verify and sign in</x-ui.button>
            </form>

            <div class="flex items-center gap-3 my-5" aria-hidden="true">
                <span class="flex-1 border-t border-vl-line"></span>
                <span class="text-[13px] text-vl-mut">or</span>
                <span class="flex-1 border-t border-vl-line"></span>
            </div>

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
</x-layouts.public>
