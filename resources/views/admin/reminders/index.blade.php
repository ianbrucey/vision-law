<x-layouts.app title="Reminder deliveries · Vision Law">
    <x-ui.page-header title="Reminder deliveries" subtitle="Delivery ledger for your organization, including failures.">
    </x-ui.page-header>

    <x-ui.card title="Deliveries">
        <form method="GET" action="{{ route('admin.reminders.index') }}">
            <x-ui.select name="status" label="Status" :options="array_combine($statuses, $statuses)" placeholder="All statuses" :value="$status" />
            <x-ui.button variant="primary" type="submit">Filter</x-ui.button>
        </form>

        @if ($deliveries->isEmpty())
            <p>No deliveries found.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Recipient</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Last error</th>
                        <th>Scheduled</th>
                        <th>Sent</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deliveries as $delivery)
                        <tr>
                            <td>{{ $delivery->payload['item_title'] ?? $delivery->item?->title }}</td>
                            <td>{{ $delivery->recipient_email ?? $delivery->recipient?->email }}</td>
                            <td>{{ $delivery->channel }}</td>
                            <td>{{ $delivery->status }}</td>
                            <td>{{ $delivery->attempts }}</td>
                            <td>{{ $delivery->last_error }}</td>
                            <td>{{ $delivery->scheduled_for?->toDayDateTimeString() }}</td>
                            <td>{{ $delivery->sent_at?->toDayDateTimeString() }}</td>
                            <td>{{ $delivery->created_at?->toDayDateTimeString() }}</td>
                            <td>
                                @if (in_array($delivery->status, $replayable, true))
                                    <form method="POST" action="{{ route('admin.reminders.replay', $delivery) }}">
                                        @csrf
                                        <x-ui.button variant="secondary" size="sm" type="submit">Replay</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</x-layouts.app>
