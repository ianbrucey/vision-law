{{--
    admin/invitations/index.blade.php — T-01 STUB.

    Ticket 2/3 builds the real admin invitations page (invitation table, invite
    form, one-time copy-link banner, mail-transport notice). This stub exists
    only so the T-01 response layer has a renderable view target; it renders
    the same data the JSON contract exposes (never token_hash).
--}}
<x-layouts.app title="Invitations · Vision Law">
    <x-ui.page-header title="Invitations" />

    @if (session('invitation_accept_url'))
        <x-ui.banner tone="info" title="Invitation created">
            Share this one-time accept link — it is shown once and never stored:
            <span class="break-all">{{ session('invitation_accept_url') }}</span>
        </x-ui.banner>
    @endif

    <x-ui.card>
        <ul>
            @foreach ($invitations as $invitation)
                <li>{{ $invitation['email'] }} · {{ $invitation['role'] }} · {{ $invitation['status'] }}</li>
            @endforeach
        </ul>
    </x-ui.card>
</x-layouts.app>
