{{--
    public/landing.blade.php — the Vision Systems landing page (spec
    013, Ticket 3; 03-contract.md §Landing page section contract).

    Sections in contract order: nav, hero, platform, packs, trust, CTA,
    footer — composed only from <x-vs.*> components plus layout
    scaffolding (C-05). The single content source is the spec's
    canonical fixtures file, read once here and handed to the partials:
    no copy is invented in the views, and the old legal-only positioning
    (with its placeholder testimonial and unshipped identity claim) is
    gone with this rebuild (013-D11).

    Anonymous-safe (003 C-04): no user, org, or session data renders;
    the nav alone reflects sign-in state (010 C-07).
--}}
@php
    $fixtures = json_decode(
        (string) file_get_contents(base_path('specs/013-vision-systems-ui-landing/04-fixtures.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $brand = $fixtures['brand'];
    $landing = $fixtures['landing'];
    $switcherThemes = array_values(array_filter(
        $fixtures['themes']['sets'],
        fn (array $theme): bool => ! ($theme['light'] ?? false)
    ));
@endphp
<x-layouts.public :title="$brand['name'].' — '.$brand['tagline']" :themed="true">
    @include('public.partials.nav')
    <div class="vsl-wrap">
        @include('public.partials.hero')
        @include('public.partials.platform')
        @include('public.partials.packs')
        @include('public.partials.trust')
        @include('public.partials.cta')
    </div>
    @include('public.partials.footer')
</x-layouts.public>
