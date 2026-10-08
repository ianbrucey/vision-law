{{--
    retention/policies/index.blade.php — spec 007 T-09 (DOC-27).
    Retention policy library: versioned admin-authored rules.
    Expects: $policies.
--}}
<x-layouts.app title="Retention policies · Vision Law">
    <x-ui.page-header title="Retention policies" subtitle="Versioned rules per document category: period, trigger, disposition, legal basis. Only active versions drive the nightly evaluation.">
        <x-slot name="actions">
            <x-ui.button variant="primary" href="{{ route('admin.retention.policies.create') }}" class="min-h-[44px]">New policy</x-ui.button>
            <x-ui.button href="{{ route('admin.retention.disposition.index') }}" class="min-h-[44px]">Disposition queue</x-ui.button>
            <x-ui.button href="{{ route('admin.retention.holds.index') }}" class="min-h-[44px]">Legal holds</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.sub-nav :items="[
        ['label' => 'Policies', 'href' => route('admin.retention.policies.index'), 'active' => true],
        ['label' => 'Disposition queue', 'href' => route('admin.retention.disposition.index'), 'active' => false],
        ['label' => 'Legal holds', 'href' => route('admin.retention.holds.index'), 'active' => false],
    ]" />

    @if (session('status'))
        <x-ui.banner tone="success" class="mt-4">{{ session('status') }}</x-ui.banner>
    @endif

    @if ($policies->isEmpty())
        <x-ui.empty
            title="No retention policies yet"
            body="Author the first rule — for example, closed-matter correspondence kept 7 years, then reviewed." />
    @else
        <x-ui.card class="!p-0 overflow-hidden mt-4">
            <x-ui.table>
                <x-slot name="head">
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Period</th>
                        <th>Trigger</th>
                        <th>Disposition</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th class="relative"><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                @foreach ($policies as $policy)
                    <tr>
                        <td class="font-semibold">{{ $policy->name }}</td>
                        <td>{{ $policy->category }}</td>
                        <td>{{ $policy->periodLabel() }}</td>
                        <td>{{ str_replace('_', ' ', $policy->trigger) }}</td>
                        <td>{{ ucfirst($policy->disposition) }}</td>
                        <td>v{{ $policy->version }}</td>
                        <td><x-ui.status type="retention_policy" :value="$policy->status" /></td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button size="sm" href="{{ route('admin.retention.policies.show', $policy) }}" class="min-h-[44px]">View</x-ui.button>
                        </td>
                    </tr>
                @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>
    @endif
</x-layouts.app>
