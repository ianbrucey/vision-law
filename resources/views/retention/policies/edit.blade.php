{{--
    retention/policies/edit.blade.php — spec 007 T-09 (DOC-27).
    Editing a policy creates a NEW draft version; the current row is kept.
    Expects: $triggers, $dispositions, $units, $categories, $matterTypes, $policy.
--}}
<x-layouts.app title="Edit policy — new version · Vision Law">
    <x-ui.page-header title="Edit policy" subtitle="Saving creates version {{ $policy->version + 1 }} as a draft. Version {{ $policy->version }} is retained untouched." />

    <x-ui.banner tone="info" title="Versioned edit" class="mt-4">
        Policies are never rewritten. Your changes become a new draft version — activate it to put it in force.
    </x-ui.banner>

    <x-ui.card class="mt-4 max-w-[720px]">
        <form method="POST" action="{{ route('admin.retention.policies.update', $policy) }}">
            @csrf
            @method('PATCH')
            @include('retention.policies._form')
            <div class="flex flex-wrap gap-3 mt-2">
                <x-ui.button type="submit" variant="primary" class="min-h-[44px]">Save as new version</x-ui.button>
                <x-ui.button href="{{ route('admin.retention.policies.show', $policy) }}" class="min-h-[44px]">Cancel</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-layouts.app>
