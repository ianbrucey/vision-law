{{--
    matters/create.blade.php — spec 006 T-05, new matter (mockup §05).
--}}
<x-layouts.app title="New matter · Vision Law">
    <x-ui.page-header
        title="New matter"
        subtitle="The matter number is generated on save (MAT-2026-NNNN, unique per organization). State starts at Intake."
    >
        <x-slot name="crumbs">
            <a href="{{ route('matters.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matters</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>New</span>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card title="Matter details">
        @include('matters._form', [
            'action' => route('matters.store'),
            'httpMethod' => 'POST',
            'submitLabel' => 'Create matter',
            'typeOptions' => $typeOptions,
            'matter' => null,
            'cancelHref' => route('matters.index'),
        ])
    </x-ui.card>
</x-layouts.app>
