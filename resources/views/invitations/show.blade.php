{{--
    invitations/show.blade.php — Invitation accept page (spec 004, ticket 3).

    Reachable by guests and signed-in users alike (004-D08): the token is
    the credential. A valid token holder sees the invitation summary and —
    if signed in — an "Accept invitation" button posting to the existing
    invitations.accept endpoint; if a guest, the "Create your account" form
    (posting name + password + locked email + hidden invitation_token to
    the existing register.store) and the "Sign in" link, which carries
    ?next= back to this token URL so the holder lands here after login.
    Acceptance still enforces the email/org match rule in the service.

    004-D07 holder exception: the plaintext $token appears ONLY here — in
    the hidden invitation_token field and the sign-in next-link for guests
    (the accept form posts to the token URL for signed-in holders) —
    because the holder already possesses it via the URL. It never appears
    in admin surfaces, logs, JSON, or pages served to non-holders.

    Invalid/expired/revoked/accepted tokens never reach this view: the
    controller aborts with the identical framework 404 in all four states
    (C-06, no enumeration). The failure banner covers the web accept-path
    rejection (invitation_invalid flashed by the accept() redirect).
--}}
<x-layouts.public title="Accept invitation · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        @if (session('invitation_error') === 'invitation_invalid')
            <div class="max-w-[520px] mx-auto">
                <x-ui.banner tone="danger" title="This invitation didn't work">This invitation is invalid or has expired. Ask the person who invited you for a new link.</x-ui.banner>
            </div>
        @endif

        <x-ui.card class="max-w-[520px] mx-auto mb-4">
            <p class="text-[12px] font-bold uppercase tracking-[0.08em] text-vl-mut mb-1.5">You've been invited</p>
            <h2 class="text-[22px] text-vl-ink font-semibold">{{ $organizationName }}</h2>
            <p class="text-vl-mut text-sm mb-3">invites you to join as</p>
            <x-ui.chip>{{ $role }}</x-ui.chip>

            @if ($matterName)
                <p class="text-[14px] text-vl-ink mt-4">
                    Matter scope: <strong>{{ $matterName }}</strong><br>
                    <span class="text-vl-mut">You will see only this matter — nothing else in the organization.</span>
                </p>
            @endif

            <p class="text-[13px] text-vl-mut mt-4">
                This invitation expires {{ \Carbon\Carbon::parse($expiresAt)->format('M j, Y') }} and can be used once.
            </p>
        </x-ui.card>

        <x-ui.card class="max-w-[520px] mx-auto">
            @auth
                {{-- 004-D08: signed-in holder — accept directly via the existing
                    endpoint (the email/org match rule is enforced there). --}}
                <h2 class="text-[19px] text-vl-ink font-semibold mb-1.5">Accept this invitation</h2>

                <p class="text-sm text-vl-mut mb-3">
                    Signed in as <strong class="text-vl-ink">{{ auth()->user()->email }}</strong>.<br>
                    This invitation is for <strong class="text-vl-ink">{{ $email }}</strong> —
                    the signed-in email must match to accept.
                </p>

                <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}">
                    @csrf
                    <x-ui.button variant="primary" type="submit" class="w-full">Accept invitation</x-ui.button>
                </form>
            @else
                <h2 class="text-[19px] text-vl-ink font-semibold mb-1.5">Create your account</h2>

                <form method="POST" action="{{ route('register.store') }}">
                    @csrf
                    <input type="hidden" name="invitation_token" value="{{ $token }}">

                    <x-ui.field
                        name="email"
                        label="Email"
                        type="email"
                        :value="$email"
                        disabled
                        autocomplete="email"
                        help="Locked to the invitation — the signed-in email must match."
                    />
                    {{-- Disabled inputs don't submit; the hidden twin carries the locked value. --}}
                    <input type="hidden" name="email" value="{{ $email }}">

                    <x-ui.field name="name" label="Full name" required autocomplete="name" placeholder="Jordan Ellis" />
                    <x-ui.field
                        name="password"
                        label="Password"
                        type="password"
                        required
                        autocomplete="new-password"
                        placeholder="Minimum 12 characters"
                        help="Minimum 12 characters."
                    />

                    <x-ui.button variant="primary" type="submit" class="w-full mt-2">Create account &amp; accept</x-ui.button>
                </form>

                <div class="flex items-center gap-3 my-6" aria-hidden="true">
                    <div class="flex-1 border-t border-vl-line"></div>
                    <span class="text-[13px] text-vl-mut">or</span>
                    <div class="flex-1 border-t border-vl-line"></div>
                </div>

                <p class="text-center text-sm text-vl-mut mb-0">
                    Already have an account?
                    <a href="{{ route('login', ['next' => route('invitations.show', ['token' => $token])]) }}" class="text-vl-info font-semibold no-underline">Sign in</a>
                    with this email, then accept.
                </p>
            @endauth
        </x-ui.card>
    </div>
</x-layouts.public>
