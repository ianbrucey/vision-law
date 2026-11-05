<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Matter;
use App\Services\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 012 chat slice — front-desk chat persistence + endpoints.
 *
 * The agent service is faked at the AgentClient boundary (its own
 * Python smoke test covers the real service). What is proven here:
 * auth gating, per-user conversation create-or-resume, user-message
 * persistence with monotonic sequence, the SSE stream shape, the
 * assistant reply persisting only on a completed turn, the labeled
 * not-configured path persisting NO fake reply, the honest
 * live-disabled response, and the internal list_my_matters tool
 * endpoint's token gate + accessibleBy scoping.
 */
class FrontDeskChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('chat.live_enabled', true);
        Config::set('chat.internal_token', 'test-internal-token');
    }

    private function fakeAgent(array $health, ?callable $forward = null): void
    {
        $fake = new class($health, $forward) extends AgentClient
        {
            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            /**
             * @param  array{ok: bool, configured: bool, runner: string|null}  $healthResult
             */
            public function __construct(
                private readonly array $healthResult,
                private readonly mixed $forwardFn,
            ) {}

            public function health(): array
            {
                return $this->healthResult;
            }

            public function forwardTurn(array $payload, callable $onEvent, callable $shouldStop): array
            {
                $this->payloads[] = $payload;
                if ($this->forwardFn !== null) {
                    return ($this->forwardFn)($payload, $onEvent);
                }
                $events = [
                    ['type' => 'token', 'text' => 'You have '],
                    ['type' => 'token', 'text' => 'two matters.'],
                    ['type' => 'done', 'text' => 'You have two matters.'],
                ];
                foreach ($events as $event) {
                    $onEvent($event);
                }

                return $events;
            }
        };

        $this->app->instance(AgentClient::class, $fake);
    }

    public function test_chat_endpoints_require_authentication(): void
    {
        $this->getJson('/front-desk/status')->assertUnauthorized();
        $this->getJson('/front-desk/conversation')->assertUnauthorized();
        $this->postJson('/front-desk/messages', ['content' => 'hi'])->assertUnauthorized();
        $this->postJson('/front-desk/abort', [])->assertUnauthorized();
    }

    public function test_status_reports_live_and_configured_from_agent_health(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $user = $loader->user('user-attorney');
        $this->fakeAgent(['ok' => true, 'configured' => true, 'runner' => 'claude']);

        $this->actingAs($user)->getJson('/front-desk/status')
            ->assertOk()
            ->assertJson([
                'mode' => 'live',
                'live_enabled' => true,
                'agent_reachable' => true,
                'agent_configured' => true,
                'runner' => 'claude',
            ]);
    }

    public function test_status_reports_sample_when_live_disabled(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $user = $loader->user('user-attorney');
        Config::set('chat.live_enabled', false);

        $this->actingAs($user)->getJson('/front-desk/status')
            ->assertOk()
            ->assertJson(['mode' => 'sample', 'live_enabled' => false, 'agent_configured' => false]);
    }

    public function test_conversation_is_created_once_and_resumed_per_user(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $viewer = $loader->user('user-viewer');

        $first = $this->actingAs($attorney)->getJson('/front-desk/conversation')->assertOk();
        $second = $this->actingAs($attorney)->getJson('/front-desk/conversation')->assertOk();

        $first->assertJsonPath('conversation.scope', ChatConversation::SCOPE_FRONT_DESK);
        $first->assertJsonPath('conversation.matter_id', null);
        $this->assertSame(
            $first->json('conversation.id'),
            $second->json('conversation.id'),
            'Second call must resume the same front-desk conversation.'
        );
        $this->assertSame(1, ChatConversation::query()->where('user_id', $attorney->id)->count());

        $other = $this->actingAs($viewer)->getJson('/front-desk/conversation')->assertOk();
        $this->assertNotSame($first->json('conversation.id'), $other->json('conversation.id'));
    }

    public function test_message_streams_sse_and_persists_both_sides_in_sequence(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $user = $loader->user('user-attorney');
        $this->fakeAgent(['ok' => true, 'configured' => true, 'runner' => 'claude']);

        $response = $this->actingAs($user)->call(
            'POST',
            '/front-desk/messages',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/event-stream'],
            json_encode(['content' => 'What matters do I have?']),
        );

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('"type":"token"', $streamed);
        $this->assertStringContainsString('You have ', $streamed);
        $this->assertStringContainsString('"type":"persisted"', $streamed);

        $conversation = ChatConversation::query()->where('user_id', $user->id)->firstOrFail();
        $messages = $conversation->messages()->orderBy('sequence')->get();
        $this->assertCount(2, $messages);
        $this->assertSame(ChatMessage::ROLE_USER, $messages[0]->role);
        $this->assertSame('What matters do I have?', $messages[0]->content);
        $this->assertSame(1, $messages[0]->sequence);
        $this->assertSame(ChatMessage::ROLE_ASSISTANT, $messages[1]->role);
        $this->assertSame('You have two matters.', $messages[1]->content);
        $this->assertSame(2, $messages[1]->sequence);

        // The persisted history is returned on resume.
        $this->actingAs($user)->getJson('/front-desk/conversation')
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.content', 'You have two matters.');
    }

    public function test_unconfigured_agent_error_persists_no_fake_reply(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $user = $loader->user('user-attorney');
        $this->fakeAgent(
            ['ok' => true, 'configured' => false, 'runner' => 'claude'],
            function (array $payload, callable $onEvent): array {
                $event = [
                    'type' => 'error',
                    'code' => 'agent_not_configured',
                    'message' => 'Agent not configured: no provider key in the agent service env.',
                ];
                $onEvent($event);

                return [$event];
            },
        );

        $response = $this->actingAs($user)->call(
            'POST',
            '/front-desk/messages',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/event-stream'],
            json_encode(['content' => 'Hello?']),
        );

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('agent_not_configured', $streamed);
        $this->assertStringContainsString('"persisted":false', $streamed);

        $conversation = ChatConversation::query()->where('user_id', $user->id)->firstOrFail();
        $messages = $conversation->messages()->get();
        $this->assertCount(1, $messages, 'Only the user message persists — no fabricated assistant reply.');
        $this->assertSame(ChatMessage::ROLE_USER, $messages[0]->role);
    }

    public function test_message_when_live_disabled_is_honest_and_sends_nothing(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $user = $loader->user('user-attorney');
        Config::set('chat.live_enabled', false);

        $this->actingAs($user)->postJson('/front-desk/messages', ['content' => 'hi'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'chat_not_live');

        $conversation = ChatConversation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(ChatMessage::ROLE_USER, $conversation->messages()->first()?->role);
    }

    public function test_abort_acknowledges_only_own_conversation(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $viewer = $loader->user('user-viewer');

        $conversation = $this->actingAs($attorney)->getJson('/front-desk/conversation')
            ->json('conversation');

        $this->actingAs($attorney)->postJson('/front-desk/abort', ['conversation_id' => $conversation['id']])
            ->assertOk()
            ->assertJson(['aborted' => true]);

        $this->actingAs($viewer)->postJson('/front-desk/abort', ['conversation_id' => $conversation['id']])
            ->assertNotFound();
    }

    public function test_internal_matters_tool_requires_token_and_scopes_to_user(): void
    {
        $loader = FixtureLoader::loadMatterFixtures();
        $attorney = $loader->user('user-attorney');
        $viewer = $loader->user('user-viewer'); // no grants in the fixtures

        $expected = Matter::query()->accessibleBy($attorney)->orderByDesc('updated_at')->orderByDesc('id')->first();
        $this->assertNotNull($expected);

        // No token -> forbidden; wrong token -> forbidden.
        $this->getJson('/api/internal/matters?user_id='.$attorney->id)->assertForbidden();
        $this->withHeaders(['Authorization' => 'Bearer wrong'])
            ->getJson('/api/internal/matters?user_id='.$attorney->id)->assertForbidden();

        $response = $this->withHeaders(['Authorization' => 'Bearer test-internal-token'])
            ->getJson('/api/internal/matters?user_id='.$attorney->id)
            ->assertOk();

        $numbers = collect($response->json('matters'))->pluck('number');
        $this->assertTrue($numbers->contains($expected->matter_number));
        $sample = collect($response->json('matters'))->firstWhere('number', $expected->matter_number);
        $this->assertSame($expected->title, $sample['title']);
        $this->assertSame($expected->lifecycle_state, $sample['status']);

        // The viewer can access nothing, so the tool returns nothing.
        $this->withHeaders(['Authorization' => 'Bearer test-internal-token'])
            ->getJson('/api/internal/matters?user_id='.$viewer->id)
            ->assertOk()
            ->assertJsonPath('matters', []);
    }
}
