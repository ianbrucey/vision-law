{{--
    retention/policies/_form.blade.php — spec 007 T-09 (DOC-27).
    Shared policy form (create + new-version edit).
    Expects: $triggers, $dispositions, $units, $categories, $matterTypes, $policy (nullable).
--}}
@php
    $p = $policy ?? null;
@endphp

<x-ui.field name="name" label="Policy name" :value="$p?->name" required
    help="The version line — edits create a new version under this name." />

<x-ui.select name="category" label="Document category" :options="$categories" :value="$p?->category" required
    help="Matched exactly against each document's category." />

<x-ui.select name="matter_type" label="Matter type" :options="['' => 'All matter types'] + $matterTypes" :value="$p?->matter_type" optional
    help="Leave on all types to apply org-wide for the category." />

<div class="flex flex-wrap gap-4">
    <div class="flex-1 min-w-[140px]">
        <x-ui.field name="period_amount" label="Retention period" type="number" :value="old('period_amount', 7)" required min="1" max="100" />
    </div>
    <div class="flex-1 min-w-[140px]">
        <x-ui.select name="period_unit" label="Period unit" :options="$units" :value="old('period_unit', 'years')" required />
    </div>
</div>

<x-ui.select name="trigger" label="Retention trigger" :options="$triggers" :value="$p?->trigger" required
    help="When the retention clock starts: matter close, document date, or a fixed anchor date." />

<x-ui.field name="fixed_date" label="Fixed anchor date" type="date" :value="$p?->fixed_date?->format('Y-m-d')" optional
    help="Required only for the fixed-date trigger." />

<x-ui.select name="disposition" label="Disposition" :options="$dispositions" :value="$p?->disposition" required
    help="Destroy needs two distinct approvers; review is manual; archive moves bytes to cold storage." />

<x-ui.textarea name="legal_basis" label="Legal basis" :value="$p?->legal_basis" required rows="3"
    help="The statute, regulation, or policy this rule implements." />
