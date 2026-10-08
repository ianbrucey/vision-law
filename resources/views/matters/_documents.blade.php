{{--
    matters/_documents.blade.php — spec 006 T-05, detail tab: Documents
    (mockup §02).

    EXPLICIT Phase-3 placeholder: a defined empty panel (never a dead link).
    The received/sent register below is real (this spec, T-03 document log)
    and append-only — corrections are new rows, never edits.

    Expects: $matter, $canEdit, $logs (paginator), $directionOptions,
    $methodOptions.
--}}
<x-ui.empty title="Document management arrives in Phase 3">
    Uploads, versions, and previews will live here. The received/sent register
    below is available now.
</x-ui.empty>

<x-ui.card title="Received / sent log" class="mb-5">
    @if ($canEdit)
        <x-slot name="headerActions">
            <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'log-item-modal' })">
                Log an item
            </x-ui.button>
        </x-slot>
    @endif

    @if ($logs->total() === 0)
        <x-ui.empty title="No log entries yet">
            Record items received from or sent to counterparties, courts, and
            counsel.
        </x-ui.empty>
    @else
        <x-ui.table>
            <x-slot name="head">
                <th scope="col">Direction</th>
                <th scope="col">Counterparty</th>
                <th scope="col">Date</th>
                <th scope="col">Method</th>
                <th scope="col">Notes</th>
            </x-slot>
            <x-slot name="body">
                @foreach ($logs as $log)
                    <tr>
                        <td><x-ui.chip :tone="$log->direction === 'received' ? 'info' : 'ok'">{{ $directionOptions[$log->direction] ?? $log->direction }}</x-ui.chip></td>
                        <td>{{ $log->counterparty }}</td>
                        <td class="whitespace-nowrap">{{ fmtDate($log->logged_at) }}</td>
                        <td class="text-vl-mut">{{ $methodOptions[$log->method] ?? $log->method }}</td>
                        <td class="text-vl-mut">{{ $log->notes ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
        <x-ui.pagination :paginator="$logs" />
    @endif

    <p class="text-[13px] text-vl-mut mt-3">
        Append-only: entries are never edited — corrections are new rows.
    </p>
</x-ui.card>

@if ($canEdit)
    <x-ui.modal id="log-item-modal" title="Log an item">
        <form method="POST" action="{{ route('matters.document-log.store', $matter) }}">
            @csrf
            <x-ui.select name="direction" label="Direction" :options="$directionOptions" placeholder="Select…" required />
            <x-ui.field name="counterparty" label="Counterparty" required placeholder="e.g. Apex Construction Inc." />
            <div class="grid gap-x-5 min-[821px]:grid-cols-2">
                <x-ui.field name="logged_at" label="Date" type="date" required :value="now()->toDateString()" />
                <x-ui.select name="method" label="Method" :options="$methodOptions" placeholder="Select…" required />
            </div>
            <x-ui.textarea name="notes" label="Notes" optional rows="2" placeholder="e.g. Initial disclosures" />
            <x-slot name="footer">
                <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit">Log item</x-ui.button>
            </x-slot>
        </form>
    </x-ui.modal>
@endif
