{{--
    matters/denied.blade.php — spec 006 T-05, denied state (mockup §06).

    ONE generic page for every denial class: unassigned users,
    other-organization users, and anonymous visitors all see the same page —
    no existence leak, no distinguishing detail. Matches the app's denied
    pattern: the middleware renders {code:"not_found"} for machines; this is
    the human page for the same verdict.

    Note: RequireMatterAccess (T-06-owned) currently returns JSON for every
    denial; this view is the Blade counterpart, ready whenever the denial
    path negotiates HTML.
--}}
<x-layouts.app title="Not found · Vision Law">
    <div class="mx-auto max-w-[560px] pt-10">
        <x-ui.empty
            title="Not found"
            actionHref="{{ route('matters.index') }}"
            actionLabel="Back to matters"
        >
            This matter doesn't exist or you don't have access to it.
        </x-ui.empty>
    </div>
</x-layouts.app>
