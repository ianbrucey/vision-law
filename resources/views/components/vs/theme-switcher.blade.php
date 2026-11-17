{{--
    <x-vs.theme-switcher> — the Vision Systems theme switcher (spec 013,
    03-contract.md §Theme structure; 05-ui.md §Theme behavior).

    A real <select> with a visible label, as the UI spec requires. The
    options are the theme sets passed in — the landing passes the
    non-light sets; the five light sets are defined and tested but held
    out of the production switcher until 013-O02 is decided. Selecting a
    theme swaps the root data-vs-theme attribute client-side: no reload,
    no request, no server state (013-O06). The change handler and the
    localStorage persistence are wired by the themed layout
    (layouts/public, themed surfaces); this component renders the
    control only, and renders exactly once per page (its id is the
    handler's hook).

    Why this lives in the family: architecture door 3 allows raw form
    controls only inside the component families' own views, and the
    contract requires a real <select> in the landing nav — so the
    switcher is the vs family's select door, beside the composer (its
    other form-control door).

    Props: themes (list of ['slug' => ..., 'name' => ...]),
           current (the slug rendered selected — the server-rendered
           default, ocean-deep).
--}}
@props([
    'themes' => [],
    'current' => 'ocean-deep',
])

<div {{ $attributes->merge(['class' => 'vs-switcher']) }}>
    <label class="vs-switcher__label" for="vs-theme-select">Theme</label>
    <select class="vs-switcher__select" id="vs-theme-select" name="vs-theme">
        @foreach ($themes as $theme)
            <option value="{{ $theme['slug'] }}" @selected($theme['slug'] === $current)>{{ $theme['name'] }}</option>
        @endforeach
    </select>
</div>
