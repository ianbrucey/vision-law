{{--
    admin/invitations/index.blade.php — spec 004, Ticket 2.

    Admin invitations page: create form, invitation table, one-time accept-link
    banner, revoke modal. Light-only surface.

    Leak-sentinel note (004-D07): the plaintext token may appear ONLY in the
    one-time accept-link banner below (session('invitation_accept_url')),
    flashed exactly once at creation. It never appears in the table, logs, or
    JSON — the $invitations payloads carry no token_hash.
--}}
<x-layouts.app title="Team invitations · Vision Law">
    <x-ui.page-header
        title="Team invitations"
        :subtitle="'Invite people to ' . $orgName . '. Invitations expire after 7 days and are single-use.'"
    >
        <x-slot name="actions">
            <x-ui.button variant="primary" href="#invite-form">Invite someone</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.banner tone="info" title="Email delivery is not configured">
        After you create an invitation, copy the accept link yourself and share it
        with the invitee — email sending is not set up yet and the link will not
        be delivered.
    </x-ui.banner>

    @if (session('invitation_accept_url'))
        <x-ui.banner tone="success" title="Invitation created — share this link">
            <div class="flex flex-wrap items-end gap-2.5 mt-2">
                <x-ui.field
                    name="accept_link"
                    label="Accept link"
                    :value="session('invitation_accept_url')"
                    readonly
                    class="mb-0 grow min-w-[260px]"
                    id="accept-link-input"
                />
                <x-ui.button variant="secondary" size="sm" type="button" id="copy-accept-link">
                    Copy
                </x-ui.button>
            </div>
            <p class="text-[13px] text-vl-mut mt-2">
                Shown once, to you only. The token is never stored in readable form
                and never appears anywhere else.
            </p>
            <script>
                (function () {
                    var btn = document.getElementById('copy-accept-link');
                    var input = document.getElementById('accept-link-input');
                    if (!btn || !input) return;
                    btn.addEventListener('click', function () {
                        var markCopied = function () { btn.textContent = 'Copied'; };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(input.value).then(markCopied, function () {
                                input.select();
                                document.execCommand('copy');
                                markCopied();
                            });
                        } else {
                            input.select();
                            document.execCommand('copy');
                            markCopied();
                        }
                    });
                })();
            </script>
        </x-ui.banner>
    @endif

    <x-ui.card title="Invite someone" id="invite-form" class="mb-6 scroll-mt-24">
        <form method="POST" action="{{ route('admin.invitations.store') }}">
            @csrf
            <div class="grid gap-x-5 md:grid-cols-2">
                <x-ui.field
                    name="email"
                    label="Email"
                    type="email"
                    required
                    placeholder="colleague@firm.com"
                />
                <x-ui.select
                    name="role"
                    label="Role"
                    :options="$roleOptions"
                    required
                    help="Outside counsel gets no matter access without an explicit grant — add a matter scope below."
                />
                <x-ui.select
                    name="matter_id"
                    label="Matter scope"
                    :options="$matterOptions"
                    optional
                    class="md:col-span-2"
                />
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" type="submit">Send invitation</x-ui.button>
                <span class="text-[13px] text-vl-mut">Expires in 7 days · single use</span>
            </div>
        </form>
    </x-ui.card>

    @if ($invitations->isEmpty())
        <x-ui.empty
            title="No invitations yet"
            actionHref="#invite-form"
            actionLabel="Invite someone"
        >
            Invite your first team member to get them into {{ $orgName }}.
        </x-ui.empty>
    @else
        <x-ui.card padded="false" class="mb-6">
            <x-ui.table>
                <x-slot name="head">
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Matter scope</th>
                    <th scope="col">Status</th>
                    <th scope="col">Expires</th>
                    <th scope="col">Invited</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </x-slot>
                <x-slot name="body">
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td><strong>{{ $invitation['email'] }}</strong></td>
                            <td><x-ui.chip>{{ $invitation['role'] }}</x-ui.chip></td>
                            <td>
                                {{ $invitation['matter_id'] !== null && isset($matterTitles[$invitation['matter_id']])
                                    ? $matterTitles[$invitation['matter_id']]
                                    : '—' }}
                            </td>
                            <td><x-ui.status type="invitation" :value="$invitation['status']" /></td>
                            <td>
                                @if (in_array($invitation['status'], ['pending', 'expired'], true))
                                    {{ \Illuminate\Support\Carbon::parse($invitation['expires_at'])->format('M j, Y') }}
                                @else
                                    <span class="text-vl-mut">—</span>
                                @endif
                            </td>
                            <td class="text-vl-mut">
                                {{ \Illuminate\Support\Carbon::parse($invitation['created_at'])->format('M j, Y') }}
                            </td>
                            <td>
                                @if ($invitation['status'] === 'pending')
                                    <x-ui.button
                                        size="sm"
                                        type="button"
                                        @click="$dispatch('vl-open-modal', { id: 'revoke-{{ $invitation['id'] }}' })"
                                    >
                                        Revoke
                                    </x-ui.button>
                                @else
                                    <span class="text-vl-mut">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>

        @foreach ($invitations->where('status', 'pending') as $invitation)
            <x-ui.modal id="revoke-{{ $invitation['id'] }}" title="Revoke this invitation?">
                Revoking cancels the accept link for
                <strong>{{ $invitation['email'] }}</strong> immediately.
                They will not be able to join. This cannot be undone — to invite
                them again, create a new invitation.
                <x-slot name="footer">
                    <x-ui.button
                        variant="ghost"
                        type="button"
                        @click="$dispatch('vl-close-modal')"
                    >
                        Cancel
                    </x-ui.button>
                    <form
                        method="POST"
                        action="{{ route('admin.invitations.destroy', $invitation['id']) }}"
                        class="inline"
                    >
                        @csrf
                        @method('DELETE')
                        <x-ui.button variant="danger" type="submit">Revoke invitation</x-ui.button>
                    </form>
                </x-slot>
            </x-ui.modal>
        @endforeach
    @endif
</x-layouts.app>
