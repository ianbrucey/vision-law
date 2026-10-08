{{--
    retention/holds/index.blade.php — spec 007 T-09 (DOC-28).
    Org-wide active legal hold list. Releasing a hold requires the
    legal-hold role + a logged reason (403 otherwise).
    Expects: $holds.
--}}
<x-layouts.app title="Legal holds · Vision Law">
    <x-ui.page-header title="Legal holds" subtitle="Active holds block hard delete (423), version purge, and matter archival until released.">
        <x-slot name="actions">
            <x-ui.button href="{{ route('admin.retention.policies.index') }}" class="min-h-[44px]">Policies</x-ui.button>
            <x-ui.button href="{{ route('admin.retention.disposition.index') }}" class="min-h-[44px]">Disposition queue</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.sub-nav :items="[
        ['label' => 'Policies', 'href' => route('admin.retention.policies.index'), 'active' => false],
        ['label' => 'Disposition queue', 'href' => route('admin.retention.disposition.index'), 'active' => false],
        ['label' => 'Legal holds', 'href' => route('admin.retention.holds.index'), 'active' => true],
    ]" />

    @if (session('status'))
        <x-ui.banner tone="success" class="mt-4">{{ session('status') }}</x-ui.banner>
    @endif

    <x-ui.banner tone="warn" title="Release is gated" class="mt-4">
        Releasing a hold requires the legal-hold role and a logged reason. Holds are placed from a matter or document; the history is retained after release.
    </x-ui.banner>

    @if ($holds->isEmpty())
        <x-ui.empty
            title="No active holds"
            body="Nothing is under a legal hold. Place one from a document or matter when litigation is reasonably anticipated." />
    @else
        <x-ui.card class="!p-0 overflow-hidden mt-4">
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Scope</th>
                        <th>Matter</th>
                        <th>Reason</th>
                        <th>Placed by</th>
                        <th>Placed</th>
                        <th class="relative"><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                @foreach ($holds as $hold)
                    <tr>
                        <td>
                            @if ($hold->document_id)
                                <span class="font-semibold">Document</span>
                                <span class="block text-vl-mut text-[13px]">{{ $hold->document?->title }}</span>
                            @else
                                <span class="font-semibold">Matter</span>
                            @endif
                        </td>
                        <td>{{ $hold->matter?->title ?? '—' }}</td>
                        <td class="max-w-[280px]">{{ $hold->reason }}</td>
                        <td>{{ $hold->creator?->name ?? '—' }}</td>
                        <td>{{ $hold->created_at?->format('Y-m-d') }}</td>
                        <td class="text-right whitespace-nowrap">
                            <details>
                                <summary class="inline-flex items-center justify-center min-h-[44px] px-3.5 py-[7px] text-[13.5px] font-semibold rounded-vl-control bg-vl-bad text-white cursor-pointer list-none">Release</summary>
                                <form method="POST" action="{{ route('admin.retention.holds.release', $hold) }}" class="mt-2 text-left">
                                    @csrf
                                    <x-ui.textarea name="reason" label="Release reason" required rows="2"
                                        help="Required — logged with the release." />
                                    <x-ui.button type="submit" size="sm" variant="danger" class="min-h-[44px]">Confirm release</x-ui.button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.app>
