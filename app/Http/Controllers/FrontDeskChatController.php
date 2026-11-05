<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec 012 chat slice: the front-desk chat endpoints.
 *
 * Laravel owns auth, persistence, and the browser-facing SSE stream;
 * the agent service (loopback only) is reached exclusively through
 * AgentClient with the shared internal token. The assistant reply is
 * persisted only when the turn completes (a done event), so a stopped
 * turn never leaves a partial reply in the record — the user message
 * always persists, which keeps sequence ordering provable.
 */
class FrontDeskChatController extends Controller
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Mode + configuration status for frontdesk.js. Honest labels only:
     * live requires the config flag AND a reachable agent service; the
     * configured flag reports whether the service holds a provider key.
     */
    public function status(Request $request): JsonResponse
    {
        $this->actor($request);

        $live = (bool) config('chat.live_enabled');
        $health = $live ? $this->agent->health() : ['ok' => false, 'configured' => false, 'runner' => null];

        return response()->json([
            'mode' => $live && $health['ok'] ? 'live' : 'sample',
            'live_enabled' => $live,
            'agent_reachable' => $health['ok'],
            'agent_configured' => $health['configured'],
            'runner' => $health['runner'],
        ]);
    }

    /**
     * Create-or-resume the user's front-desk conversation and return it
     * with its persisted messages.
     */
    public function conversation(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $conversation = $this->frontDeskConversation($user);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation),
            'messages' => $conversation->messages->map(fn (ChatMessage $m): array => $this->messagePayload($m))->values(),
        ]);
    }

    /**
     * Persist the user message, stream the agent turn back as SSE, and
     * persist the assistant reply on completion.
     */
    public function storeMessage(Request $request): StreamedResponse|JsonResponse
    {
        $user = $this->actor($request);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
        ]);

        $conversation = $this->frontDeskConversation($user);

        $userMessage = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_USER,
            'content' => $validated['content'],
            'sequence' => $conversation->nextSequence(),
        ]);

        if (! (bool) config('chat.live_enabled')) {
            return response()->json([
                'code' => 'chat_not_live',
                'message' => 'The agent is not connected yet — live chat is disabled, so this stays a sample preview and nothing was sent to an agent.',
                'user_message' => $this->messagePayload($userMessage),
            ], 503);
        }

        $history = $conversation->messages()
            ->orderBy('sequence')
            ->get()
            ->map(fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        $payload = [
            'session_id' => $conversation->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'org_id' => $user->org_id,
            'message' => $validated['content'],
            'history' => $history,
            'context' => [
                'scope' => ChatConversation::SCOPE_FRONT_DESK,
                'user_name' => $user->name,
            ],
        ];

        $agent = $this->agent;
        $conversationId = $conversation->id;

        return response()->stream(function () use ($agent, $payload, $conversationId): void {
            $assistantText = '';
            $sawDone = false;
            $sawError = null;

            $send = function (array $event): void {
                echo 'data: '.json_encode($event)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $send(['type' => 'start', 'conversation_id' => $conversationId]);

            $agent->forwardTurn(
                $payload,
                function (array $event) use ($send, &$assistantText, &$sawDone, &$sawError): void {
                    if (($event['type'] ?? null) === 'token' && isset($event['text']) && is_string($event['text'])) {
                        $assistantText .= $event['text'];
                    }
                    if (($event['type'] ?? null) === 'done') {
                        $sawDone = true;
                        if ($assistantText === '' && isset($event['text']) && is_string($event['text'])) {
                            $assistantText = $event['text'];
                        }
                    }
                    if (($event['type'] ?? null) === 'error') {
                        $sawError = $event;
                    }
                    $send($event);
                },
                fn (): bool => connection_aborted() === 1,
            );

            if (connection_aborted() === 1) {
                return; // stopped by the user: no partial reply persisted
            }

            if ($sawError !== null && ! $sawDone) {
                $send(['type' => 'end', 'persisted' => false]);

                return;
            }

            if ($assistantText !== '') {
                $conversation = ChatConversation::query()->find($conversationId);
                if ($conversation !== null) {
                    $assistant = $conversation->messages()->create([
                        'role' => ChatMessage::ROLE_ASSISTANT,
                        'content' => $assistantText,
                        'sequence' => $conversation->nextSequence(),
                    ]);
                    $send(['type' => 'persisted', 'message' => $this->messagePayload($assistant)]);
                }
            }

            $send(['type' => 'end', 'persisted' => $assistantText !== '']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stop path. Streaming turns are aborted by the client closing the
     * SSE request (AbortController in frontdesk.js); this endpoint is
     * the explicit stop for clients that cannot hold the stream open.
     * Turns are stateless on the Laravel side in this slice, so a stop
     * is acknowledged once the conversation is confirmed to be the
     * caller's own.
     */
    public function abort(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $validated = $request->validate([
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['conversation_id'])) {
            $owned = ChatConversation::query()
                ->where('id', $validated['conversation_id'])
                ->where('user_id', $user->id)
                ->exists();
            if (! $owned) {
                return response()->json(['code' => 'not_found'], 404);
            }
        }

        return response()->json(['aborted' => true]);
    }

    private function frontDeskConversation(User $user): ChatConversation
    {
        /** @var ChatConversation $conversation */
        $conversation = ChatConversation::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'scope' => ChatConversation::SCOPE_FRONT_DESK,
                'matter_id' => null,
            ],
            [
                'org_id' => $user->org_id,
                'title' => 'Front desk',
            ],
        );
        $conversation->load('messages');

        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'scope' => $conversation->scope,
            'matter_id' => $conversation->matter_id,
            'title' => $conversation->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'sequence' => $message->sequence,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
