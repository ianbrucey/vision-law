{{--
    matters/_team.blade.php — spec 006 T-05, detail tab: Team & sharing
    (mockup §04).

    Assignment rides on matter grants (T-04): the team table, revoke flow
    (destructive pattern), and related matters with restricted-matter
    rendering (leak sentinel — no title, no metadata for ungranted matters).

    "Assign someone" is intentionally absent: there is no user-directory
    endpoint for non-admin matter managers in the 03-contract, so a picker
    cannot be built without inventing contract surface (recorded deviation
    from the mockup).

    Expects: $matter, $canManage, $canEdit, $grants (MatterGrant with
    user/team), $ownerCount, $related (LinkController@index shape),
    $grantRoleLabels, $linkTypeOptions.
--}}
<x-ui.card title="Assigned team" class="mb-5">
    <p class="text-[13.5px] text-vl-mut mb-4">Only owners and org admins assign; the final owner cannot be removed.</p>

    @if ($grants->isEmpty())
        <x-ui.empty title="No team assigned yet">
            This matter has no grants. Owners and org admins can assign team members.
        </x-ui.empty>
    @else
        <x-ui.table>
            <x-slot name="head">
                <th scope="col">User</th>
                <th scope="col">Role</th>
                <th scope="col">Expires</th>
                @if ($canManage)
                    <th scope="col"><span class="sr-only">Actions</span></th>
                @endif
            </x-slot>
            <x-slot name="body">
                @foreach ($grants as $grant)
                    @php
                        $isFinalOwner = $ownerCount <= 1 && in_array($grant->role, ['owner', 'matter_owner'], true);
                        $expired = $grant->expires_at !== null && $grant->expires_at->isPast();
                    @endphp
                    <tr @if ($expired) class="opacity-60" @endif>
                        <td>
                            @if ($grant->user !== null)
                                <strong>{{ $grant->user->name }}</strong>
                                @if ($grant->role === 'outside_counsel')
                                    <span class="text-vl-mut text-[12.5px]">(external)</span>
                                @endif
                            @else
                                <strong>{{ $grant->team?->name ?? '—' }}</strong>
                                <x-ui.chip>team</x-ui.chip>
                            @endif
                        </td>
                        <td><x-ui.chip>{{ $grantRoleLabels[$grant->role] ?? $grant->role }}</x-ui.chip></td>
                        <td class="text-vl-mut whitespace-nowrap">
                            {{ $grant->expires_at !== null ? fmtDate($grant->expires_at) : '—' }}
                            @if ($expired)
                                <span class="text-[12.5px]">(expired)</span>
                            @endif
                        </td>
                        @if ($canManage)
                            <td class="text-right">
                                @if ($grant->user_id !== null && ! $isFinalOwner)
                                    <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'revoke-grant-{{ $grant->getKey() }}' })">
                                        Revoke
                                    </x-ui.button>
                                @else
                                    <span class="text-vl-mut">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
    @endif
</x-ui.card>

<x-ui.card title="Related matters" class="mb-5">
    @if (count($related) === 0)
        <x-ui.empty title="No related matters">
            Link matters that share a client, are consolidated, or are companions.
        </x-ui.empty>
    @else
        <x-ui.table>
            <x-slot name="head">
                <th scope="col">Matter</th>
                <th scope="col">Relationship</th>
                @if ($canEdit)
                    <th scope="col"><span class="sr-only">Actions</span></th>
                @endif
            </x-slot>
            <x-slot name="body">
                @foreach ($related as $link)
                    <tr>
                        <td>
                            @if (($link['related_matter']['restricted'] ?? false) === true)
                                <em class="text-vl-mut">restricted matter</em>
                            @else
                                <a href="{{ route('matters.show', $link['related_matter']['id']) }}" class="font-semibold text-vl-ink underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $link['related_matter']['matter_number'] }} · {{ $link['related_matter']['title'] }}</a>
                            @endif
                        </td>
                        <td><x-ui.chip>{{ $linkTypeOptions[$link['link_type']] ?? $link['link_type'] }}</x-ui.chip></td>
                        @if ($canEdit)
                            <td class="text-right">
                                <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'unlink-{{ $link['id'] }}' })">
                                    Unlink
                                </x-ui.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
        <p class="text-[13px] text-vl-mut mt-3">Matters you can't access render as “restricted matter” — no title, no metadata.</p>
    @endif
</x-ui.card>

@if ($canManage)
    @foreach ($grants as $grant)
        @if ($grant->user_id !== null && ! ($ownerCount <= 1 && in_array($grant->role, ['owner', 'matter_owner'], true)))
            <x-ui.modal id="revoke-grant-{{ $grant->getKey() }}" title="Revoke this grant?">
                <p class="mb-4">
                    <strong>{{ $grant->user?->name }}</strong>
                    ({{ $grantRoleLabels[$grant->role] ?? $grant->role }})
                    will lose access to {{ $matter->matter_number }} immediately.
                </p>
                <x-ui.banner tone="danger">
                    Revoking is immediate. They will no longer see this matter anywhere — not even in their list.
                </x-ui.banner>
                <x-slot name="footer">
                    <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                        Cancel
                    </x-ui.button>
                    <form method="POST" action="{{ route('matters.assignments.destroy', [$matter, $grant]) }}" class="inline">
                        @csrf
                        @method('DELETE')
                        <x-ui.button variant="danger" type="submit">Revoke access</x-ui.button>
                    </form>
                </x-slot>
            </x-ui.modal>
        @endif
    @endforeach
@endif

@if ($canEdit)
    @foreach ($related as $link)
        <x-ui.modal id="unlink-{{ $link['id'] }}" title="Unlink these matters?">
            <p>
                The <strong>{{ $linkTypeOptions[$link['link_type']] ?? $link['link_type'] }}</strong>
                link between {{ $matter->matter_number }} and the related matter will be removed.
            </p>
            <x-slot name="footer">
                <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                    Cancel
                </x-ui.button>
                <form method="POST" action="{{ route('matters.links.destroy', [$matter, $link['id']]) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-ui.button variant="danger" type="submit">Unlink</x-ui.button>
                </form>
            </x-slot>
        </x-ui.modal>
    @endforeach
@endif
