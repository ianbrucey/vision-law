<x-layouts.app title="Notifications · Vision Law">
    <x-ui.page-header title="Notifications" subtitle="Reminders delivered to you in Vision Law.">
    </x-ui.page-header>

    @if ($deliveries->isEmpty())
        <x-ui.card title="Inbox">
            <p>No notifications yet.</p>
        </x-ui.card>
    @else
        <x-ui.card title="Inbox">
            <ul>
                @foreach ($deliveries as $delivery)
                    <li>
                        <a href="{{ route('calendar.items.show', $delivery->item_id) }}">{{ $delivery->payload['item_title'] ?? $delivery->item?->title }}</a>
                        @if (!empty($delivery->payload['rule_label']))
                            <span>— {{ $delivery->payload['rule_label'] }}</span>
                        @endif
                        <span>({{ $delivery->status }})</span>
                        @if ($delivery->sent_at)
                            <span>{{ $delivery->sent_at->toDayDateTimeString() }}</span>
                        @endif
                        @if (!empty($delivery->payload['digest_items']) && is_array($delivery->payload['digest_items']))
                            <ul>
                                @foreach ($delivery->payload['digest_items'] as $entry)
                                    <li>{{ is_array($entry) ? ($entry['item_title'] ?? '') : '' }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($delivery->status === 'sent' || $delivery->status === 'snoozed')
                            <form method="POST" action="{{ route('notifications.acknowledge', $delivery) }}">
                                @csrf
                                <x-ui.button variant="secondary" size="sm" type="submit">Acknowledge</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('notifications.snooze', $delivery) }}">
                                @csrf
                                <x-ui.field name="until" label="Snooze until" type="datetime-local" />
                                <x-ui.button variant="secondary" size="sm" type="submit">Snooze</x-ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</x-layouts.app>
