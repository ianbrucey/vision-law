{{--
    matters/_comment.blade.php — one comment in the timeline thread
    (mockup §03). Included by matters/_timeline.blade.php for top-level
    comments and their one-level replies.

    Markdown is rendered per C-06 — with html_input=escape so raw HTML in a
    comment body is neutralized (XSS-safe); this justifies the {!! !!}
    per docs/UI_Standards.md markup rule 3.

    Expects: $comment (MatterComment with author), $isReply (bool),
    $canComment, $canManage, $actorId, $matter (inherited).
--}}
@php
    $trashed = $comment->trashed();
    $isAuthor = (string) $comment->author_id === (string) $actorId;
    $editWindowOpen = ! $trashed && $comment->created_at !== null && $comment->created_at->gt(now()->subDay());
    $mayEdit = ! $trashed && ($canManage || ($isAuthor && $editWindowOpen));
    $mayDelete = ! $trashed && ($canManage || $isAuthor);
@endphp

<div @class(['border border-vl-line rounded-vl-card px-4 py-3.5 mb-3 bg-vl-card', 'ml-8' => $isReply]) x-data="{ editing: false }">
    @if ($trashed)
        <p class="text-[14px] text-vl-mut italic m-0">
            <span class="font-bold not-italic">{{ $comment->author?->name ?? 'Unknown' }}</span>
            <span class="text-[12.5px] ml-2">{{ fmtDT($comment->deleted_at) }}</span>
        </p>
        <p class="text-[14px] text-vl-mut italic mt-2 mb-0">Comment deleted.</p>
    @else
        <p class="m-0">
            <span class="font-bold text-[14px]">{{ $comment->author?->name ?? 'Unknown' }}</span>
            <span class="text-vl-mut text-[12.5px] ml-2" title="{{ fmtDT($comment->created_at) }}">{{ fmtDate($comment->created_at) }} · {{ fmtRelative($comment->created_at) }}</span>
            @if ($comment->edited_at !== null)
                <x-ui.chip class="ml-2">edited</x-ui.chip>
            @endif
        </p>
        <div class="text-[14px] mt-2 vl-comment-body" x-show="! editing">
            {!! \Illuminate\Support\Str::markdown($comment->body, ['html_input' => 'escape']) !!}
        </div>

        <div class="flex flex-wrap items-center gap-2 mt-2.5" x-show="! editing">
            @if ($canComment && ! $isReply)
                <x-ui.button
                    variant="ghost"
                    size="sm"
                    type="button"
                    @click="replyTo = '{{ $comment->getKey() }}'; replyToName = '{{ addslashes($comment->author?->name ?? 'Unknown') }}'; document.getElementById('comment-body-input')?.focus()"
                >
                    Reply
                </x-ui.button>
            @endif
            @if ($mayEdit)
                <x-ui.button variant="ghost" size="sm" type="button" @click="editing = true">
                    Edit
                </x-ui.button>
            @endif
            @if ($mayDelete)
                {{-- No confirmation modal: deletion writes a tombstone, so the
                     record is preserved and the action is reversible by
                     design (05-ui.md destructive pattern covers
                     delete-matter / revoke-grant only). --}}
                <form method="POST" action="{{ route('matters.comments.destroy', [$matter, $comment]) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-ui.button variant="danger" size="sm" type="submit">Delete</x-ui.button>
                </form>
            @endif
        </div>

        @if ($mayEdit)
            <form method="POST" action="{{ route('matters.comments.update', [$matter, $comment]) }}" class="mt-3" x-show="editing" x-cloak>
                @csrf
                @method('PATCH')
                <x-ui.textarea name="body" label="Edit comment" rows="3" required :value="$comment->body" />
                <div class="flex flex-wrap items-center gap-2.5">
                    <x-ui.button variant="primary" size="sm" type="submit">Save</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" type="button" @click="editing = false">Cancel</x-ui.button>
                </div>
            </form>
        @endif
    @endif

    @if (! $isReply && $comment->relationLoaded('replies') && $comment->replies->isNotEmpty())
        <div class="mt-3">
            @foreach ($comment->replies as $reply)
                @include('matters._comment', ['comment' => $reply, 'isReply' => true])
            @endforeach
        </div>
    @endif
</div>
