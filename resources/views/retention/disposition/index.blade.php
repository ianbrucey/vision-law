{{--
    retention/disposition/index.blade.php — spec 007 T-09 (DOC-29).
    Disposition queue: destroy / extend / archive decisions.
    Expects: $entries (paginated).
--}}
<x-layouts.app title="Disposition queue · Vision Law">
    <x-ui.page-header title="Disposition queue" subtitle="Flagged documents awaiting a decision. Destroy needs two distinct approvers — the same user twice does not count.">
        <x-slot name="actions">
            <x-ui.button href="{{ route('admin.retention.policies.index') }}" class="min-h-[44px]">Policies</x-ui.button>
            <x-ui.button href="{{ route('admin.retention.holds.index') }}" class="min-h-[44px]">Legal holds</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.sub-nav :items="[
        ['label' => 'Policies', 'href' => route('admin.retention.policies.index'), 'active' => false],
        ['label' => 'Disposition queue', 'href' => route('admin.retention.disposition.index'), 'active' => true],
        ['label' => 'Legal holds', 'href' => route('admin.retention.holds.index'), 'active' => false],
    ]" />

    @if (session('status'))
        <x-ui.banner tone="success" class="mt-4">{{ session('status') }}</x-ui.banner>
    @endif

    @if ($entries->isEmpty())
        <x-ui.empty
            title="Queue is clear"
            body="Nothing is waiting for a disposition decision. Flagged documents appear here when their retention threshold passes." />
    @else
        <x-ui.card class="!p-0 overflow-hidden mt-4">
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Document</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Approvals</th>
                        <th>Requested</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                @foreach ($entries as $entry)
                    <tr>
                        <td class="font-semibold">{{ $entry->document?->title ?? '—' }}</td>
                        <td>{{ ucfirst($entry->action) }}</td>
                        <td><x-ui.status type="disposition" :value="$entry->status" /></td>
                        <td>{{ $entry->approvalCount() }}{{ $entry->action === 'destroy' ? ' / 2' : '' }}</td>
                        <td>{{ $entry->created_at?->format('Y-m-d') }}</td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button size="sm" href="{{ route('admin.retention.disposition.show', $entry) }}" class="min-h-[44px]">Review</x-ui.button>
                        </td>
                    </tr>
                @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>

        <div class="mt-4">
            <x-ui.pagination :paginator="$entries" />
        </div>
    @endif
</x-layouts.app>
