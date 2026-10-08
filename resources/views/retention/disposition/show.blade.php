{{--
    retention/disposition/show.blade.php — spec 007 T-09 (DOC-29).
    Decide one queue entry: approve (dual-control for destroy) or reject.
    Expects: $entry, $approverNames (id => name).
--}}
<x-layouts.app title="Disposition decision · Vision Law">
    <x-ui.page-header title="Disposition decision" subtitle="{{ ucfirst($entry->action) }} · {{ $entry->document?->title ?? 'document removed' }}">
        <x-slot name="actions">
            <x-ui.status type="disposition" :value="$entry->status" />
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.banner tone="success" class="mt-4">{{ session('status') }}</x-ui.banner>
    @endif

    @if ($entry->action === 'destroy')
        <x-ui.banner tone="danger" title="Dual control required" class="mt-4">
            Destruction is permanent. Two <strong>distinct</strong> approvers are required — the same user approving twice does not count. After the second approval the document's blobs and versions are deleted and a permanent tombstone is retained.
        </x-ui.banner>
    @endif

    <div class="grid gap-4 mt-4 md:grid-cols-2">
        <x-ui.card>
            <x-ui.kv :items="[
                ['label' => 'Document', 'value' => $entry->document?->title ?? '—'],
                ['label' => 'Action', 'value' => ucfirst($entry->action)],
                ['label' => 'Requested by', 'value' => $entry->requester?->name ?? 'System (nightly evaluation)'],
                ['label' => 'Requested', 'value' => $entry->created_at?->format('Y-m-d H:i') ?? '—'],
                ['label' => 'Extend to', 'value' => $entry->new_retention_date?->format('Y-m-d') ?? '—'],
                ['label' => 'Decided', 'value' => $entry->decided_at?->format('Y-m-d H:i') ?? '—'],
            ]" />

            @if (! empty($entry->approvals))
                <h2 class="font-bold text-[14px] mt-4 mb-2">Approvals ({{ $entry->approvalCount() }})</h2>
                <ul class="space-y-1.5 text-[13.5px]">
                    @foreach ($entry->approvals as $approval)
                        <li>{{ $approverNames[$approval['approver_id']] ?? 'Unknown user' }}
                            <span class="text-vl-mut">· {{ \Carbon\Carbon::parse($approval['decided_at'])->format('Y-m-d H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if ($entry->status === 'pending')
            <x-ui.card>
                <h2 class="font-bold text-[15px] mb-3">Decide</h2>
                <form method="POST" action="{{ route('admin.retention.disposition.approve', $entry) }}">
                    @csrf
                    <x-ui.textarea name="note" label="Decision note" optional rows="2"
                        help="Recorded in the audit trail with your approval." />
                    <x-ui.button type="submit" variant="primary" class="min-h-[44px] w-full sm:w-auto">
                        {{ $entry->action === 'destroy' ? 'Approve destruction' : 'Approve '.strtolower($entry->action) }}
                    </x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.retention.disposition.reject', $entry) }}" class="mt-4 pt-4 border-t border-vl-line">
                    @csrf
                    <x-ui.textarea name="reason" label="Rejection reason" required rows="2" />
                    <x-ui.button type="submit" variant="danger" class="min-h-[44px] w-full sm:w-auto">Reject</x-ui.button>
                </form>
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty title="Decision recorded" body="This entry is {{ $entry->status }}. The audit trail holds the full decision record." />
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
