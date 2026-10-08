<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterComment;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Matter comment endpoints (spec 006 T-03; 03-contract.md §Routes).
 *
 * - POST   /matters/{matter}/comments                 → store
 * - PATCH  /matters/{matter}/comments/{comment}       → update (author 24h, or manage)
 * - DELETE /matters/{matter}/comments/{comment}       → destroy (tombstone; author or manage)
 * - POST   /matters/{matter}/timeline/read            → markRead (read marker; no audit row)
 *
 * Authorization (006-D13, Ticket 6): store carries matter.access:comment
 * at the middleware (effective role ≥ outside_counsel; a pure viewer may
 * not comment). update and destroy carry matter.access:view at the
 * middleware — the contract's "author (24h) or :manage" rule needs the
 * comment row, so the author-or-manage check stays here:
 *   update  — the author within 24h, or effective role ≥ :manage;
 *   destroy — the author, or effective role ≥ :manage.
 * Denials are 403 {code:"forbidden"} — the matter is visible, the role is
 * not sufficient.
 */
class CommentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        // :comment is enforced by RequireMatterAccess before this runs
        // (Ticket 6) — behavior identical to the former in-controller check.

        /** @var array{body: string, parent_id?: string|null} $validated */
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $comment = MatterService::addComment($matter, $validated, $actor);

        return response()->json($this->resource($comment->load('author')), 201);
    }

    public function update(Request $request, string $matter, string $comment): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $commentModel = $this->resolveComment($matterModel, $comment);

        /** @var array{body: string} $validated */
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $asManager = $this->isManager($matterModel, $actor);

        if (! $asManager && (string) $commentModel->author_id !== (string) $actor->getKey()) {
            abort(response()->json(['code' => 'forbidden'], 403));
        }

        $updated = MatterService::editComment($matterModel, $commentModel, $validated['body'], $actor, $asManager);

        return response()->json($this->resource($updated->load('author')));
    }

    public function destroy(Request $request, string $matter, string $comment): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $commentModel = $this->resolveComment($matterModel, $comment);

        $isAuthor = (string) $commentModel->author_id === (string) $actor->getKey();

        if (! $isAuthor && ! $this->isManager($matterModel, $actor)) {
            abort(response()->json(['code' => 'forbidden'], 403));
        }

        MatterService::deleteComment($matterModel, $commentModel, $actor);

        return response()->json($this->resource($commentModel->load('author')));
    }

    /**
     * Record the actor's timeline read marker (MAT-16 unread counts).
     * Telemetry — deliberately writes no audit row.
     */
    public function markRead(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        MatterService::markTimelineRead($matter, $actor);

        return response()->json([
            'matter_id' => (string) $matter->getKey(),
            'last_read_at' => now()->toIso8601String(),
        ]);
    }

    private function isManager(Matter $matter, User $actor): bool
    {
        return AccessControl::roleSatisfies(
            AccessControl::effectiveMatterRole($actor, $matter),
            AccessControl::ACTION_MIN_ROLE['manage']
        );
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveComment(Matter $matter, string $raw): MatterComment
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        return MatterComment::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->firstOrFail();
    }

    private function authorizedMatter(Request $request): Matter
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        return $matter;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(MatterComment $comment): array
    {
        return [
            'id' => (string) $comment->getKey(),
            'parent_id' => $comment->parent_id !== null ? (string) $comment->parent_id : null,
            'author' => [
                'id' => (string) $comment->author_id,
                'name' => $comment->author?->name,
                'email' => $comment->author?->email,
            ],
            'body' => $comment->body,
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'tombstone' => $comment->deleted_at !== null,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
