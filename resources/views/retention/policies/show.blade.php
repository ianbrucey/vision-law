{{--
    retention/policies/show.blade.php — spec 007 T-09 (DOC-27).
    Policy detail + version history + read-only simulator.
    Expects: $policy, $versions, $simulation (optional).
--}}
<x-layouts.app title="Retention policy · Vision Law">
    <x-ui.page-header title="{{ $policy->name }}" subtitle="Version {{ $policy->version }} · {{ $policy->periodLabel() }} · {{ str_replace('_', ' ', $policy->trigger) }} → {{ $policy->disposition }}">
        <x-slot name="actions">
            <x-ui.status type="retention_policy" :value="$policy->status" />
            @if ($policy->status === 'draft')
                <form method="POST" action="{{ route('admin.retention.policies.activate', $policy) }}" class="inline">
                    @csrf
                    <x-ui.button type="submit" variant="primary" class="min-h-[44px]">Activate</x-ui.button>
                </form>
            @endif
            <x-ui.button href="{{ route('admin.retention.policies.edit', $policy) }}" class="min-h-[44px]">Edit (new version)</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.banner tone="success" class="mt-4">{{ session('status') }}</x-ui.banner>
    @endif

    <div class="grid gap-4 mt-4 md:grid-cols-2">
        <x-ui.card>
            <x-ui.kv :items="[
                ['label' => 'Category', 'value' => $policy->category],
                ['label' => 'Matter type', 'value' => $policy->matter_type ?? 'All types'],
                ['label' => 'Trigger', 'value' => str_replace('_', ' ', $policy->trigger)],
                ['label' => 'Fixed anchor', 'value' => $policy->fixed_date?->format('Y-m-d') ?? '—'],
                ['label' => 'Disposition', 'value' => ucfirst($policy->disposition)],
                ['label' => 'Legal basis', 'value' => $policy->legal_basis],
            ]" />
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-bold text-[15px] mb-3">Simulator — preview affected documents</h2>
            <p class="text-[13.5px] text-vl-mut mb-3">Read-only. Shows which existing documents this {{ $policy->status }} version would flag — no flags are created, nothing is queued.</p>
            <form method="POST" action="{{ route('admin.retention.policies.simulate', $policy) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" class="min-h-[44px]">Run simulation</x-ui.button>
            </form>

            @isset($simulation)
                <div class="mt-4">
                    <x-ui.stat label="Documents that would be flagged" :value="$simulation['count']" />
                    @if (! empty($simulation['documents']))
                        <ul class="mt-3 space-y-2">
                            @foreach ($simulation['documents'] as $doc)
                                <li class="text-[13.5px] border border-vl-line rounded-vl-control px-3 py-2">
                                    <span class="font-semibold">{{ $doc['title'] }}</span>
                                    <span class="text-vl-mut">· {{ $doc['matter_number'] }}</span>
                                    @if ($doc['due_at'])
                                        <span class="block text-vl-mut">Retention due {{ \Carbon\Carbon::parse($doc['due_at'])->format('Y-m-d') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($simulation['count'] > count($simulation['documents']))
                            <p class="text-[13px] text-vl-mut mt-2">Showing first {{ count($simulation['documents']) }} of {{ $simulation['count'] }}.</p>
                        @endif
                    @endif
                </div>
            @endisset
        </x-ui.card>
    </div>

    <x-ui.card class="mt-4">
        <h2 class="font-bold text-[15px] mb-3">Version history</h2>
        <x-ui.table>
            <x-slot name="head">
                <tr><th>Version</th><th>Status</th><th>Period</th><th>Disposition</th><th>Updated</th><th class="relative"><span class="sr-only">Actions</span></th></tr>
            </x-slot>
            <x-slot name="body">
            @foreach ($versions as $version)
                <tr @if ($version->id === $policy->id) class="font-semibold" @endif>
                    <td>v{{ $version->version }}</td>
                    <td><x-ui.status type="retention_policy" :value="$version->status" /></td>
                    <td>{{ $version->periodLabel() }}</td>
                    <td>{{ ucfirst($version->disposition) }}</td>
                    <td>{{ $version->updated_at?->format('Y-m-d') }}</td>
                    <td class="text-right">
                        @if ($version->id !== $policy->id)
                            <x-ui.button size="sm" href="{{ route('admin.retention.policies.show', $version) }}" class="min-h-[44px]">View</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </x-slot>
        </x-ui.table>
    </x-ui.card>
</x-layouts.app>
