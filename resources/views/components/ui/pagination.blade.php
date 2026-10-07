{{--
    <x-ui.pagination> — LengthAwarePaginator passthrough (spec 002 03-contract.md,
    mockup §07).

    Props: paginator (LengthAwarePaginator, required).
    Renders the paginator in the token set (previous / numbered pages / next).
    Renders nothing when there is only one page.
--}}
@props([
    'paginator' => null,
])

@php
    throw_unless(
        $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator,
        new \InvalidArgumentException('x-ui.pagination requires a "paginator" prop (LengthAwarePaginator).')
    );

    $pgCurrent = $paginator->currentPage();
    $pgLast = $paginator->lastPage();

    // Numbered-page window: every page when there are few, otherwise first +
    // a sliding window around the current page + last, with ellipses in gaps.
    $pgPages = [];
    if ($pgLast <= 7) {
        $pgPages = range(1, $pgLast);
    } else {
        $pgPages[] = 1;
        if ($pgCurrent - 2 > 2) {
            $pgPages[] = '…';
        }
        foreach (range(max(2, $pgCurrent - 2), min($pgLast - 1, $pgCurrent + 2)) as $pgPage) {
            $pgPages[] = $pgPage;
        }
        if ($pgCurrent + 2 < $pgLast - 1) {
            $pgPages[] = '…';
        }
        $pgPages[] = $pgLast;
    }

    $pgItemClass = 'inline-flex items-center justify-center min-w-9 min-h-9 rounded-vl-control no-underline text-[14px] px-2.5 border border-transparent text-vl-ink';
    $pgLinkClass = $pgItemClass . ' hover:border-vl-line';
    $pgCurrentClass = $pgItemClass . ' bg-vl-ink text-white font-bold';
    $pgDisabledClass = $pgItemClass . ' text-vl-mut opacity-50';
@endphp

@if ($paginator->hasPages())
    <nav aria-label="Pagination" {{ $attributes->merge(['class' => 'flex items-center justify-center gap-1.5 mt-4']) }}>
        @if ($paginator->onFirstPage())
            <span class="{{ $pgDisabledClass }}" aria-disabled="true">‹ Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $pgLinkClass }}">‹ Prev</a>
        @endif

        @foreach ($pgPages as $pgPage)
            @if ($pgPage === '…')
                <span class="{{ $pgDisabledClass }}" aria-hidden="true">…</span>
            @elseif ($pgPage === $pgCurrent)
                <span class="{{ $pgCurrentClass }}" aria-current="page">{{ $pgPage }}</span>
            @else
                <a href="{{ $paginator->url($pgPage) }}" class="{{ $pgLinkClass }}">{{ $pgPage }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $pgLinkClass }}">Next ›</a>
        @else
            <span class="{{ $pgDisabledClass }}" aria-disabled="true">Next ›</span>
        @endif
    </nav>
@endif
