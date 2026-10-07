{{--
    <x-ui.card> — the container for everything (spec 002 03-contract.md).

    Props: title (string|null — Georgia card heading), padded (bool, default true).
    Named slot: header-actions (right-aligned card actions).

    Visuals (shadow, radius, padding) live in the .vl-card class in
    resources/css/app.css — views never hardcode them.
--}}
@props([
    'title' => null,
    'padded' => true,
])

<div {{ $attributes->merge(['class' => 'vl-card' . ($padded ? '' : ' vl-card-flush')]) }}>
    @if ($title || isset($headerActions))
        <div class="flex items-start justify-between gap-4 mb-4">
            @if ($title)
                <h2 class="vl-card-title text-[19px] text-vl-ink">{{ $title }}</h2>
            @endif
            @isset($headerActions)
                <div class="shrink-0">{{ $headerActions }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</div>
