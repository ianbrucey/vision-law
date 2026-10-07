{{--
    <x-ui.select> — select wrapper; same label/old()/error contract as <x-ui.field>.

    Props: name (required), label (required), id (optional override — otherwise derived
           from name), options (array<string,string> value => label, required),
           placeholder (string|null — disabled first option), value (default null,
           falls back to old(name)), required (bool), optional (bool), help (string|null),
           error (string|null — falls back to $errors->first(name)).

    Fail-loud: missing name/label throws; non-array options throws.
--}}
@props([
    'name' => null,
    'label' => null,
    'id' => null,
    'options' => null,
    'placeholder' => null,
    'value' => null,
    'required' => false,
    'optional' => false,
    'help' => null,
    'error' => null,
])

@php
    throw_unless($name && $label, new \InvalidArgumentException('x-ui.select requires both "name" and "label" props.'));
    throw_unless(is_array($options), new \InvalidArgumentException('x-ui.select requires "options" as an array of value => label pairs.'));

    $id = $id ?: rtrim(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $name), '-');
    $selected = (string) ($value ?? old($name) ?? '');
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $hasError = $message !== null && $message !== '';
    $borderClass = $hasError ? 'border-vl-bad' : 'border-vl-line';
    $inputClass = 'w-full border ' . $borderClass . ' rounded-vl-control px-3.5 py-2.5 text-[15px] bg-vl-card text-vl-text';
@endphp

<div class="mb-4 max-w-[520px]">
    <label for="{{ $id }}" class="block text-[12px] font-bold uppercase tracking-[0.08em] text-vl-ink mb-1.5">{{ $label }}@if ($optional) <span class="text-vl-mut font-normal normal-case tracking-normal">(optional)</span>@endif</label>
    <select
        {{ $attributes->merge(['class' => $inputClass, 'id' => $id, 'name' => $name]) }}
        @required($required)
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        @if ($hasError) aria-describedby="{{ $id }}-error" @endif
    >
        @if ($placeholder)
            <option value="" disabled @selected($selected === '')>{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected($selected === (string) $optValue)>{{ $optLabel }}</option>
        @endforeach
    </select>
    @if ($help)
        <p class="text-[13px] text-vl-mut mt-1.5">{{ $help }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" role="alert" class="text-[13px] text-vl-bad mt-1.5 font-semibold">{{ $message }}</p>
    @endif
</div>
