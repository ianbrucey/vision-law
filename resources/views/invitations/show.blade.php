{{--
    invitations/show.blade.php — T-01 STUB.

    Ticket 2/3 builds the real accept page (spec 004 C-05: org name, role chip,
    matter name if scoped, expiry note; "Create your account" + "Sign in"
    paths). This stub renders the contract's accept-view data only.

    NOTE: the plaintext token is deliberately NOT rendered — the architecture
    leak sentinel (tests/Architecture/LeakSentinelTest.php) asserts it never
    appears in a response body. Ticket 2/3 must resolve how the register form
    and sign-in next-link carry the token without tripping that door.
--}}
<x-layouts.public title="Accept invitation · Vision Law">
    <div class="px-6 pt-[72px] pb-24 min-h-[70vh]">
        <x-ui.card class="max-w-[520px] mx-auto">
            @if (session('invitation_error') === 'invitation_invalid')
                <x-ui.banner tone="danger">This invitation is invalid or has expired.</x-ui.banner>
            @endif

            <h2 class="text-[24px] text-vl-ink font-semibold mb-1.5">You've been invited</h2>
            <p class="text-vl-mut text-sm mb-7">{{ $organizationName }} · Role: {{ $role }}</p>

            <dl class="text-[14.5px] text-vl-ink space-y-2">
                <div><dt class="text-vl-mut text-[12.5px]">Email</dt><dd>{{ $email }}</dd></div>
                @if ($matterName)
                    <div><dt class="text-vl-mut text-[12.5px]">Matter</dt><dd>{{ $matterName }}</dd></div>
                @endif
                <div><dt class="text-vl-mut text-[12.5px]">Expires</dt><dd>{{ $expiresAt }}</dd></div>
            </dl>
        </x-ui.card>
    </div>
</x-layouts.public>
