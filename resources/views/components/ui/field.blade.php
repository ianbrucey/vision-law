{{--
    <x-ui.field> — text-style input wrapper (spec 002 03-contract.md).

    Props: name (required), label (required), id (optional override — otherwise derived
           from name), type (default text), value (default null, falls back to old(name)),
           required (bool), optional (bool — renders the "(optional)" marker; do not set
           with required), help (string|null), error (string|null — falls back to
           $errors->first(name), renders inline under the field).

    Fail-loud: missing name or label throws at render — a label-less field is never
    acceptable.
--}}
@props([
    'name' => null,
    'label' => null,
    'id' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'optional' => false,
    'help' => null,
    'error' => null,
])

@php
    throw_unless($name && $label, new \InvalidArgumentException('x-ui.field requires both "name" and "label" props.'));

    $id = $id ?: rtrim(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $name), '-');
    $finalValue = $value ?? old($name);
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $hasError = $message !== null && $message !== '';
    $borderClass = $hasError ? 'border-vl-bad' : 'border-vl-line';
    $inputClass = 'w-full border ' . $borderClass . ' rounded-vl-control px-3.5 py-2.5 text-[15px] bg-vl-card text-vl-text';
@endphp

<div class="mb-4 max-w-[520px]">
    <label for="{{ $id }}" class="block text-[12px] font-bold uppercase tracking-[0.08em] text-vl-ink mb-1.5">{{ $label }}@if ($optional) <span class="text-vl-mut font-normal normal-case tracking-normal">(optional)</span>@endif</label>
    <input
        {{ $attributes->merge(['class' => $inputClass, 'id' => $id, 'name' => $name, 'type' => $type]) }}
        @if ($finalValue !== null) value="{{ $finalValue }}" @endif
        @required($required)
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        @if ($hasError) aria-describedby="{{ $id }}-error" @endif
    >
    @if ($help)
        <p class="text-[13px] text-vl-mut mt-1.5">{{ $help }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" role="alert" class="text-[13px] text-vl-bad mt-1.5 font-semibold">{{ $message }}</p>
    @endif
</div>
