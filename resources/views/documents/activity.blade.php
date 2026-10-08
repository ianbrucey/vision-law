{{--    documents/activity.blade.php — spec 007 T-10 (C-14).

    Per-document Activity tab: a read model over audit_events (006-D04 —
    no new table), newest first, paginated. Every row the actor sees
    belongs to a document they may already view (DocumentAccess :view in
    the controller); payloads never carry tokens or hashes.

    Phone-first: the table scrolls inside its card (overflow-x-auto) —
    the page itself never scrolls horizontally (C-15).

    Expects: $matter, $document, $events (paginator of AuditEvent),
             $descriptions (id → one-line summary), $canShare (bool).
--}}
<x-layouts.app title="Activity · {{ $document->title }} · Vision Law">
    <x-ui.page-header
        title="Activity"
        :subtitle="$document->title"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.index', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
        </x-slot>
        <x-slot name="actions">
            <x-ui.button :href="route('documents.activity.export', $matter)">Export CSV</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('documents.partials.doc-tabs', ['activeTab' => 'activity'])

    <x-ui.card class="!p-0 overflow-hidden mt-4">
        @if ($events->isEmpty())
            <x-ui.empty
                title="No activity yet"
                body="Uploads, previews, downloads, versions, shares, and retention actions on this document will appear here."
            />
        @else
            {{-- The table scrolls inside the card on narrow screens. --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-[14px]">
                    <thead>
                        <tr class="border-b border-vl-line text-vl-mut text-[12.5px] uppercase tracking-wide">
                            <th scope="col" class="px-4 py-3 font-semibold whitespace-nowrap">When</th>
                            <th scope="col" class="px-4 py-3 font-semibold whitespace-nowrap">Event</th>
                            <th scope="col" class="px-4 py-3 font-semibold whitespace-nowrap">Actor</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr class="border-b border-vl-line last:border-0">
                                <td class="px-4 py-3 whitespace-nowrap text-vl-mut">{{ $event->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3 font-mono text-[12.5px] whitespace-nowrap">{{ $event->event }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $event->actor?->name ?? 'system' }}</td>
                                <td class="px-4 py-3 text-vl-mut">{{ $descriptions[(string) $event->getKey()] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-vl-line">
                {{ $events->links() }}
            </div>
        @endif
    </x-ui.card>

    <p class="mt-3 text-[12.5px] text-vl-mut">
        Append-only audit trail — rows cannot be edited or deleted.
        <a href="{{ route('documents.activity.export', $matter) }}" class="underline underline-offset-2">Download the matter's document-activity CSV</a>.
    </p>
</x-layouts.app>
