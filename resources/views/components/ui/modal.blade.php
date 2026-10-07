{{--
    <x-ui.modal> — the ONLY door for dialogs (docs/UI_Standards.md, spec 002
    03-contract.md, mockup §08).

    Props: id (string, required — the Alpine event target),
           title (string, required).
    Slots: default (body), footer (action row, e.g. Cancel link + danger button).

    Open from anywhere: $dispatch('vl-open-modal', { id: '...' }).
    Close: $dispatch('vl-close-modal'). Escape closes; focus is trapped while
    open (Alpine x-trap via the @alpinejs/focus plugin); clicking the scrim
    closes. Never hand-roll a dialog.

    NOTE: the trigger element must live inside an Alpine component scope
    (Alpine only initializes directives under x-data). The app shell
    (layouts/app.blade.php) wraps every page in x-data, so triggers work
    there; on a bare page, wrap the trigger in an x-data element yourself.

    The panel starts hidden via x-cloak (see the [x-cloak] rule in
    resources/css/app.css) so it never flashes before Alpine boots.
--}}
@props([
    'id' => null,
    'title' => null,
])

@php
    throw_unless($id, new \InvalidArgumentException('x-ui.modal requires an "id" prop.'));
    throw_unless($title, new \InvalidArgumentException('x-ui.modal requires a "title" prop.'));
@endphp

<div
    x-data="{ open: false }"
    x-on:vl-open-modal.window="if ($event.detail?.id === '{{ $id }}') open = true"
    x-on:vl-close-modal.window="open = false"
    x-on:keydown.escape.window="if (open) open = false"
    x-trap="open"
    x-show="open"
    x-cloak
    {{ $attributes->merge(['class' => 'fixed inset-0 z-50 flex items-center justify-center p-6']) }}
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    <div class="absolute inset-0 bg-vl-ink/45" x-on:click="open = false" aria-hidden="true"></div>
    <div class="relative w-full max-w-[480px] rounded-[12px] bg-vl-card p-6 shadow-[0_8px_40px_rgba(31,42,55,0.22)]">
        <h3 id="{{ $id }}-title" class="font-serif text-[20px] text-vl-ink mb-2">{{ $title }}</h3>
        <div class="text-[14.5px] text-vl-mut mb-[18px]">{{ $slot }}</div>
        @isset($footer)
            <div class="flex items-center justify-end gap-2.5">{{ $footer }}</div>
        @endisset
    </div>
</div>
