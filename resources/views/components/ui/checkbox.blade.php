{{--
    <x-ui.checkbox> — checkbox wrapper; label rendered beside the box, clickable.

    Props: name (required), label (required), id (optional override — otherwise derived
           from name), checked (bool, default false — falls back to old(name)),
           value (string, default "1"), help (string|null),
           error (string|null — falls back to $errors->first(name)).

    Fail-loud: missing name or label throws.
--}}
@props([
    'name' => null,
    'label' => null,
    'id' => null,
    'checked' => false,
    'value' => '1',
    'help' => null,
    'error' => null,
])

@php
    throw_unless($name && $label, new \InvalidArgumentException('x-ui.checkbox requires both "name" and "label" props.'));

    $id = $id ?: rtrim(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $name), '-');
    $isChecked = $checked || (bool) old($name);
    $message = $error ?? (isset($errors) ? $errors->first($name) : null);
    $hasError = $message !== null && $message !== '';
@endphp

<div class="mb-2.5 max-w-[520px]">
    <div class="flex gap-2.5 items-start">
        <input
            type="checkbox"
            {{ $attributes->merge(['class' => 'w-5 h-5 mt-0.5 shrink-0 accent-vl-ink', 'id' => $id, 'name' => $name]) }}
            value="{{ $value }}"
            @checked($isChecked)
            aria-invalid="{{ $hasError ? 'true' : 'false' }}"
            @if ($hasError) aria-describedby="{{ $id }}-error" @endif
        >
        <label for="{{ $id }}" class="text-[15px] text-vl-text cursor-pointer">{{ $label }}</label>
    </div>
    @if ($help)
        <p class="text-[13px] text-vl-mut mt-1.5 ml-7">{{ $help }}</p>
    @endif
    @if ($hasError)
        <p id="{{ $id }}-error" role="alert" class="text-[13px] text-vl-bad mt-1.5 ml-7 font-semibold">{{ $message }}</p>
    @endif
</div>
