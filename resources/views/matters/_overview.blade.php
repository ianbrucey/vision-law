{{--
    matters/_overview.blade.php — spec 006 T-05, detail tab: Overview
    (mockup §02).

    Details card, derived status-summary card, parties card (add/remove via
    the T-03 party endpoints). Rendered inside matters/show.blade.php.

    Expects: $matter, $summary (statusSummary shape), $canEdit,
    $lastActivityActorName, $leadAttorneyName, $parties, $partyTypeOptions.
--}}
@php
    $docLog = $summary['document_log'] ?? ['received' => 0, 'sent' => 0];
    $lastActivity = $summary['last_activity'] ?? null;
    $lastEventLabel = $lastActivity !== null
        ? ucfirst(str_replace('_', ' ', str_replace('.', ' ', str_replace('matter.', '', (string) ($lastActivity['event'] ?? '')))))
        : null;
@endphp

<div class="grid gap-5 min-[821px]:grid-cols-2 mb-5">
    <x-ui.card title="Details">
        <x-ui.kv :items="[
            ['label' => 'Client', 'value' => $matter->client_name],
            ['label' => 'Matter type', 'value' => ucfirst(str_replace('_', ' ', $matter->matter_type))],
            ['label' => 'Opened', 'value' => fmtDate($matter->created_at)],
            ['label' => 'Lead attorney', 'value' => $leadAttorneyName ?? '—'],
        ]" />
    </x-ui.card>
    <x-ui.card title="Status summary">
        <p class="text-[13px] text-vl-mut mb-3">Derived on read — computed live, never stored.</p>
        <x-ui.kv :items="[
            ['label' => 'Documents', 'value' => $docLog['received'] . ' received · ' . $docLog['sent'] . ' sent'],
            ['label' => 'Tasks', 'value' => '— (Phase 4)'],
            ['label' => 'Unread comments', 'value' => (string) ($summary['unread_comments'] ?? 0)],
            ['label' => 'Last activity', 'value' => $lastActivity !== null
                ? $lastEventLabel . ' by ' . ($lastActivityActorName ?? 'System') . ' · ' . fmtRelative($lastActivity['at'])
                : '—'],
            ['label' => 'Holds', 'value' => 'None'],
        ]" />
    </x-ui.card>
</div>

<x-ui.card title="Parties" class="mb-5">
    @if ($canEdit)
        <x-slot name="headerActions">
            <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'add-party-modal' })">
                Add party
            </x-ui.button>
        </x-slot>
    @endif

    @if ($parties->isEmpty())
        <x-ui.empty title="No parties yet">
            Add the client and any opposing parties, counsel, or witnesses.
        </x-ui.empty>
    @else
        <x-ui.table>
            <x-slot name="head">
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Contact</th>
                @if ($canEdit)
                    <th scope="col"><span class="sr-only">Actions</span></th>
                @endif
            </x-slot>
            <x-slot name="body">
                @foreach ($parties as $party)
                    <tr>
                        <td><strong>{{ $party->name }}</strong></td>
                        <td><x-ui.chip>{{ $partyTypeOptions[$party->party_type] ?? $party->party_type }}</x-ui.chip></td>
                        <td class="text-vl-mut">{{ $party->email ?? $party->phone ?? '—' }}</td>
                        @if ($canEdit)
                            <td class="text-right">
                                <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'remove-party-{{ $party->getKey() }}' })">
                                    Remove
                                </x-ui.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
    @endif
</x-ui.card>

@if ($canEdit)
    <x-ui.modal id="add-party-modal" title="Add party">
        <form method="POST" action="{{ route('matters.parties.store', $matter) }}">
            @csrf
            <x-ui.field name="name" label="Name" required placeholder="e.g. Sterling Manufacturing Co." />
            <x-ui.select name="party_type" label="Party type" :options="$partyTypeOptions" placeholder="Select a type…" required />
            <x-ui.field name="email" label="Email" type="email" optional placeholder="name@example.com" />
            <x-slot name="footer">
                <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit">Add party</x-ui.button>
            </x-slot>
        </form>
    </x-ui.modal>

    @foreach ($parties as $party)
        <x-ui.modal id="remove-party-{{ $party->getKey() }}" title="Remove this party?">
            <p>
                <strong>{{ $party->name }}</strong>
                ({{ $partyTypeOptions[$party->party_type] ?? $party->party_type }})
                will be removed from {{ $matter->matter_number }}.
            </p>
            <x-slot name="footer">
                <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                    Cancel
                </x-ui.button>
                <form method="POST" action="{{ route('matters.parties.destroy', [$matter, $party]) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-ui.button variant="danger" type="submit">Remove party</x-ui.button>
                </form>
            </x-slot>
        </x-ui.modal>
    @endforeach
@endif
