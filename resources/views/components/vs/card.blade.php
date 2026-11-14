{{--
    <x-vs.card> — the generic Vision Systems surface (spec 013,
    03-contract.md §Landing page section contract: the Packs and Trust
    sections are built from card; design/component-library.html .card).

    Props: title (string|null — renders a heading above the slot).
--}}
@props([
    'title' => null,
])

<section {{ $attributes->merge(['class' => 'vs-card']) }}>
    @if ($title)
        <h3 class="vs-card__title">{{ $title }}</h3>
    @endif
    {{ $slot }}
</section>
