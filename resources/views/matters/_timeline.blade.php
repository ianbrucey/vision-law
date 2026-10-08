{{--
    matters/_timeline.blade.php — spec 006 T-05, detail tab: Timeline
    (mockup §03).

    Activity is derived from the tamper-evident audit trail — newest first,
    paginated at 20 (05-ui.md). Comments live on the same timeline: markdown,
    one-level threading, 24h author edit, tombstones on delete (C-06).

    Expects: $matter, $canComment, $canManage, $activity (paginator of
    AuditEvent with actor), $comments (top-level MatterComment with
    author + replies.author), $actorId.
--}}
@php
    $stateLabel = fn (string $s): string => \App\View\Components\Ui\Status::MAP['matter'][$s]['label'] ?? $s;

    // Human sentence per audit event. Payload keys come from the
    // 03-contract.md §Audit events catalog; unknown events fall through to a
    // humanized event name rather than raw enum text.
    $describe = function (\App\Models\AuditEvent $event) use ($stateLabel): string {
        $p = is_array($event->payload) ? $event->payload : [];
        $note = fn (): string => ! empty($p['note']) ? ' — “' . $p['note'] . '”' : '';

        return match ($event->event) {
            'matter.created' => 'created the matter',
            'matter.updated' => 'updated the matter',
            'matter.deleted' => 'deleted the matter',
            'matter.restored' => 'restored the matter',
            'matter.closed' => 'closed the matter' . $note(),
            'matter.reopened' => 'reopened the matter' . $note(),
            'matter.transition' => 'moved ' . $stateLabel((string) ($p['from'] ?? '')) . ' → ' . $stateLabel((string) ($p['to'] ?? '')) . $note(),
            'matter.party.added' => 'added party ' . ($p['name'] ?? 'a party'),
            'matter.party.removed' => 'removed party ' . ($p['name'] ?? 'a party'),
            'matter.comment.added' => 'commented',
            'matter.comment.edited' => 'edited a comment',
            'matter.comment.deleted' => 'deleted a comment',
            'matter.mentioned' => 'mentioned ' . count($p['mentioned_user_ids'] ?? []) . ' ' . \Illuminate\Support\Str::plural('person', count($p['mentioned_user_ids'] ?? [])),
            'matter.assigned' => 'assigned a team member (' . ($p['role'] ?? 'role') . ')',
            'matter.unassigned' => 'removed a team member',
            'matter.link.added' => 'linked a related matter',
            'matter.link.removed' => 'unlinked a related matter',
            'matter.document_logged' => 'logged ' . ($p['direction'] ?? 'a') . ' document-register item',
            'matter.access.denied' => 'was denied access (' . ($p['attempted_action'] ?? 'unknown action') . ')',
            'matter.transition.denied' => 'was denied a transition',
            'matter.assign.denied' => 'was denied an assignment change',
            'matter.restore.denied' => 'was denied a restore',
            default => str_replace('_', ' ', str_replace('matter.', '', (string) $event->event)),
        };
    };
@endphp

<x-ui.card title="Activity" class="mb-5">
    @if ($activity->total() === 0)
        <x-ui.empty title="No activity yet">
            Transitions, comments, and team changes will appear here, newest first.
        </x-ui.empty>
    @else
        <ul class="list-none m-0 p-0">
            @foreach ($activity as $event)
                <li class="flex gap-3 py-3 border-b border-vl-line last:border-b-0 text-[14.5px]">
                    <span class="flex-none w-2 h-2 rounded-full bg-vl-brass mt-[7px]" aria-hidden="true"></span>
                    <span>
                        <strong>{{ $event->actor?->name ?? 'System' }}</strong>
                        {{ $describe($event) }}
                    </span>
                    <span class="ml-auto text-vl-mut text-[12.5px] whitespace-nowrap" title="{{ fmtDT($event->created_at) }}">
                        {{ fmtRelative($event->created_at) }}
                    </span>
                </li>
            @endforeach
        </ul>
        <x-ui.pagination :paginator="$activity" />
    @endif
</x-ui.card>

<x-ui.card title="Comments" class="mb-5" x-data="{ replyTo: null, replyToName: '' }">
    @if ($comments->isEmpty())
        <x-ui.empty title="No comments yet">
            Start the discussion — comments live on the timeline with the activity feed.
        </x-ui.empty>
    @else
        <div class="mb-2">
            @foreach ($comments as $comment)
                @include('matters._comment', ['comment' => $comment, 'isReply' => false])
            @endforeach
        </div>
    @endif

    @if ($canComment)
        <form method="POST" action="{{ route('matters.comments.store', $matter) }}" class="mt-4">
            @csrf
            <input type="hidden" name="parent_id" :value="replyTo">
            <div x-show="replyTo" x-cloak class="mb-3 flex items-center gap-2 text-[13.5px]">
                <x-ui.chip tone="info">Replying to <span x-text="replyToName"></span></x-ui.chip>
                <x-ui.button variant="ghost" size="sm" type="button" @click="replyTo = null; replyToName = ''">Cancel reply</x-ui.button>
            </div>
            <x-ui.textarea
                name="body"
                label="Add a comment"
                id="comment-body-input"
                rows="3"
                required
                placeholder="Replies nest one level only. Edits allowed within 24 hours."
            />
            <x-ui.button variant="primary" type="submit">Post comment</x-ui.button>
        </form>
    @endif
</x-ui.card>
