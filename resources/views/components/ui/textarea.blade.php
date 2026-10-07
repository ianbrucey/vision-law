{{--
    <x-ui.textarea> — textarea wrapper; same contract as <x-ui.field> plus rows.

    Props: name (required), label (required), id (optional override — otherwise derived
           from name), rows (int, default 4), value (default null, falls back to old(name)),
           required (bool), optional (bool), help (string|null),
           error (string|null — falls back to $errors->first(name)).

    Fail-loud: missing name or label throws.
--}}
@props([
    'name' => null,
    'label' => null,
    'id' => null,
    'rows' => 4,
    'value' => null,
    'required' => false,
    'optional' => false,
    'help' => null,
    'error' => null,
])

@php
    throw_unless($name && $label, new \InvalidArgumentException('x-ui.textarea requires both "name" and "label" props.'));

    $id = $id ?: rtrim(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $name), '-');
    $finalValue = $value ?? old($name);
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $hasError = $message !== null && $message !== '';
    $borderClass = $hasError ? 'border-vl-bad' : 'border-vl-line';
    $inputClass = 'w-full border ' . $borderClass . ' rounded-vl-control px-3.5 py-2.5 text-[15px] bg-vl-card text-vl-text';
@endphp

<div class="mb-4 max-w-[520px]">
    <label for="{{ $id }}" class="block text-[12px] font-bold uppercase tracking-[0.08em] text-vl-ink mb-1.5">{{ $label }}@if ($optional) <span class="text-vl-mut font-normal normal-case tracking-normal">(optional)</span>@endif</label>
    <textarea
        {{ $attributes->merge(['class' => $inputClass, 'id' => $id, 'name' => $name]) }}
        rows="{{ $rows }}"
        @required($required)
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        @if ($hasError) aria-describedby="{{ $id }}-error" @endif
    >{{ $finalValue }}</textarea>
    @if ($help)
        <p class="text-[13px] text-vl-mut mt-1.5">{{ $help }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" role="alert" class="text-[13px] text-vl-bad mt-1.5 font-semibold">{{ $message }}</p>
    @endif
</div>
