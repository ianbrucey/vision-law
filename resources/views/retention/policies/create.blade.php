{{--
    retention/policies/create.blade.php — spec 007 T-09 (DOC-27).
    Expects: $triggers, $dispositions, $units, $categories, $matterTypes, $policy (null).
--}}
<x-layouts.app title="New retention policy · Vision Law">
    <x-ui.page-header title="New retention policy" subtitle="Created as a draft — simulate it, then activate." />

    <x-ui.card class="mt-4 max-w-[720px]">
        <form method="POST" action="{{ route('admin.retention.policies.store') }}">
            @csrf
            @include('retention.policies._form')
            <div class="flex flex-wrap gap-3 mt-2">
                <x-ui.button type="submit" variant="primary" class="min-h-[44px]">Create draft</x-ui.button>
                <x-ui.button href="{{ route('admin.retention.policies.index') }}" class="min-h-[44px]">Cancel</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-layouts.app>
