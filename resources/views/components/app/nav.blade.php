{{--
    <x-app.nav> — the standard primary navigation (spec 010 C-05).

    Rendered by layouts/app as the default nav content when a page does not
    furnish the $nav slot. The same markup serves the desktop top bar (dark
    — links inherit the bar's white text) and the mobile drawer (light —
    links inherit body text). Link list: Home, Matters, Templates; org
    admins additionally see Invitations and Retention (the targets are
    role-gated server-side as well). The current page's item carries
    aria-current="page".

    Per 010-D09 there is deliberately no "Document search" entry yet:
    /search/documents is a JSON-only API (spec 007 backend, 001-D06) with
    no page to link to. The entry joins this list when a document-search
    page ships.
--}}
@php
    $navLinks = [
        ['Home', route('app.home')],
        ['Matters', route('matters.index')],
        ['Calendar', route('calendar.index')],
        ['Templates', route('templates.index')],
    ];

    if (auth()->user()?->hasRole('org_admin')) {
        $navLinks[] = ['Invitations', route('admin.invitations.index')];
        $navLinks[] = ['Retention', route('admin.retention.policies.index')];
    }
@endphp
@foreach ($navLinks as [$navLabel, $navHref])
    <a href="{{ $navHref }}" @if (request()->url() === $navHref) aria-current="page" @endif class="block px-2.5 py-3 text-[15px] font-medium no-underline rounded-vl-control hover:underline min-[821px]:inline-flex min-[821px]:items-center min-[821px]:py-2 min-[821px]:text-[14px]">{{ $navLabel }}</a>
@endforeach
