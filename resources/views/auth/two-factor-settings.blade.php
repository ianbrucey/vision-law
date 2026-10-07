{{--
    auth/two-factor-settings.blade.php — 2FA enrollment + management (spec 005 T-01).

    Four states (TwoFactorSettingsController@index), per 05-ui.md:
    - $recoveryCodes: once-display after confirm/regenerate — flashed by the
      POST handler, never re-read from the model, so a refresh or later
      visit renders status only (#s-codes).
    - $setupMode: restricted session — brass banner, "Confirm and continue"
      (#s-enroll-setup).
    - fresh: any authenticated user without confirmed 2FA (#s-enroll-fresh).
    - active: confirmed 2FA — regenerate / disable (#s-enroll-done).

    Leak hygiene (005-D04): the TOTP secret never appears in page source.
    The QR renders via <img src="…two-factor.qr-image"> — Fortify's
    two-factor.qr-code returns JSON {svg, url}, not an image, so an <img>
    cannot point at it (T-03 follow-up). The image endpoint serves
    Fortify's QR SVG bytes as image/svg+xml; the manual key is fetched on
    demand from the two-factor.secret-key endpoint (Alpine), never inlined.
--}}
@php
    $confirmError = $errors->getBag('confirmTwoFactorAuthentication')->first('code');
@endphp
<x-layouts.app title="Two-factor authentication · Vision Law">
    @if ($recoveryCodes !== null)
        {{-- ── 04 · Recovery codes (shown once) ─────────────────────────── --}}
        <x-ui.page-header
            title="Save your recovery codes"
            subtitle="Each code works once, if you ever lose access to your authenticator app."
        />

        <x-ui.banner tone="warn" title="Shown once — store them now">
            This is the only time these codes are displayed in full. If you
            close this page, you'll need to regenerate a new set.
        </x-ui.banner>

        <x-ui.card class="max-w-[560px] mx-auto">
            <div class="grid grid-cols-1 min-[560px]:grid-cols-2 gap-2 mb-6" role="list" aria-label="Recovery codes">
                @foreach ($recoveryCodes as $code)
                    <code role="listitem" class="font-mono text-[14px] bg-vl-paper border border-vl-line rounded-[6px] px-2.5 py-2 text-center text-vl-ink">{{ $code }}</code>
                @endforeach
            </div>

            <x-ui.button variant="primary" href="{{ route('two-factor.settings') }}" class="w-full">
                I've saved them — continue
            </x-ui.button>
        </x-ui.card>
    @elseif ($setupMode || ! $enrolled)
        {{-- ── 01/02 · Enrollment (fresh or setup mode) ──────────────────── --}}
        <x-ui.page-header
            :title="$setupMode ? 'One more step before you can continue' : 'Set up two-factor authentication'"
            :subtitle="$setupMode
                ? 'Organization admins are required to use two-factor authentication. Your session is limited to this page until setup is complete — nothing else in the app is reachable right now.'
                : 'Three steps. Your authenticator app (Google Authenticator, Authy, 1Password) generates a 6-digit code each time you sign in.'"
        />

        @if ($setupMode)
            <x-ui.banner tone="warn" title="Restricted session">
                You're signed in, but only this page is available until
                two-factor authentication is active. This is deliberate — it's
                the policy doing its job.
            </x-ui.banner>
        @endif

        {{-- Presentational 3-step indicator (05-ui.md). --}}
        <div class="flex flex-wrap gap-0 mb-8" aria-hidden="true">
            <div class="flex-1 min-w-[140px] px-3.5 py-3 border border-vl-line bg-vl-card text-[13px] rounded-l-[8px] {{ $hasPendingSecret ? 'bg-vl-ok-soft border-vl-ok' : 'bg-vl-brass-soft border-vl-brass font-bold' }}">
                <span class="block text-[11px] uppercase tracking-[0.1em] text-vl-mut mb-0.5">Step 1</span>
                Start setup
            </div>
            <div class="flex-1 min-w-[140px] px-3.5 py-3 border border-vl-line bg-vl-card text-[13px] border-l-0 {{ $hasPendingSecret ? 'bg-vl-brass-soft border-vl-brass font-bold' : '' }}">
                <span class="block text-[11px] uppercase tracking-[0.1em] text-vl-mut mb-0.5">Step 2</span>
                Scan the code
            </div>
            <div class="flex-1 min-w-[140px] px-3.5 py-3 border border-vl-line bg-vl-card text-[13px] border-l-0 rounded-r-[8px]">
                <span class="block text-[11px] uppercase tracking-[0.1em] text-vl-mut mb-0.5">Step 3</span>
                Confirm
            </div>
        </div>

        @if ($hasPendingSecret)
            <x-ui.card class="max-w-[560px] mx-auto" title="Scan with your authenticator app">
                <img
                    src="{{ route('two-factor.qr-image') }}"
                    alt="QR code to scan with your authenticator app"
                    class="w-[200px] h-[200px] border border-vl-line rounded-[8px] bg-vl-card mb-3"
                >

                <div x-data="{ manualKey: null }" class="mb-4">
                    <p class="text-[13px] text-vl-mut">
                        Can't scan?
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            x-on:click="fetch('{{ route('two-factor.secret-key') }}', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) { manualKey = j.secretKey; })"
                            x-show="! manualKey"
                        >Show the manual key</x-ui.button>
                    </p>
                    <p class="text-[13px] text-vl-mut" x-show="manualKey" x-cloak>
                        Enter this key manually:
                        <strong class="font-mono text-vl-ink" x-text="manualKey"></strong>
                    </p>
                </div>

                <form method="POST" action="{{ route('two-factor.confirm') }}">
                    @csrf

                    <x-ui.field
                        name="code"
                        label="6-digit code"
                        type="text"
                        required
                        value=""
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="······"
                        autocomplete="one-time-code"
                        help="Enter the current code from your app to confirm setup."
                        :error="$confirmError ? 'That code didn\'t work. Check your clock and try again.' : null"
                        class="font-mono text-center tracking-[0.35em]"
                    />

                    <x-ui.button variant="primary" type="submit" class="w-full">
                        {{ $setupMode ? 'Confirm and continue' : 'Confirm and activate' }}
                    </x-ui.button>
                </form>

                @if ($setupMode)
                    <p class="text-[13px] text-vl-mut bg-vl-paper border border-vl-line rounded-[8px] px-3.5 py-2.5 mt-3.5">
                        Once confirmed, your full session unlocks immediately — no need to sign in again.
                    </p>
                @endif
            </x-ui.card>
        @else
            <x-ui.card class="max-w-[560px] mx-auto" title="Start setup">
                <p class="text-[14px] text-vl-mut mb-4">
                    First we'll generate your authenticator secret and backup
                    codes. Then you'll scan the code and confirm with a
                    6-digit code from your app.
                </p>

                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf

                    <x-ui.button variant="primary" type="submit" class="w-full">
                        Start setup
                    </x-ui.button>
                </form>
            </x-ui.card>
        @endif
    @else
        {{-- ── 03 · Active ──────────────────────────────────────────────── --}}
        <x-ui.page-header
            title="Two-factor authentication"
            subtitle="Manage the second factor on your account."
        />

        <x-ui.card class="max-w-[560px] mx-auto">
            <div class="flex items-center gap-2.5 mb-1.5 flex-wrap">
                <span class="w-2.5 h-2.5 rounded-full bg-vl-ok" aria-hidden="true"></span>
                <strong class="text-vl-ink">Active</strong>
                <span class="text-vl-mut text-[13px]">— confirmed {{ fmtDate($confirmedAt) }}</span>
            </div>

            <p class="text-vl-mut text-[14px]">
                Your authenticator app generates the code at each sign-in.
                Keep your recovery codes somewhere safe — they're the way back
                in if you lose your phone.
            </p>

            <div class="flex flex-wrap gap-2.5 mt-3.5">
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                    @csrf

                    <x-ui.button variant="secondary" size="sm" type="submit">
                        Regenerate recovery codes
                    </x-ui.button>
                </form>

                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')

                    <x-ui.button variant="secondary" size="sm" type="submit">
                        Turn off two-factor
                    </x-ui.button>
                </form>
            </div>

            <p class="text-[13px] text-vl-mut bg-vl-paper border border-vl-line rounded-[8px] px-3.5 py-2.5 mt-3.5">
                Turning off requires re-entering your password. Regenerating
                codes invalidates the old set immediately.
            </p>
        </x-ui.card>
    @endif
</x-layouts.app>
