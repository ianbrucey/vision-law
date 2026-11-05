<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 012 — voice mode transcription endpoint.
 *
 * Mistral is faked at the Http boundary. What is proven here: auth
 * gating, a successful transcription returning {text} (with the key,
 * model, and file actually forwarded), the unconfigured path (503,
 * product copy only, Mistral never called), upstream failure mapping
 * to a generic 502, and upload validation (mime + 25 MB cap).
 */
class VoiceTranscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mistral.key', 'test-mistral-key');
        Config::set('services.mistral.transcribe_model', 'voxtral-mini-latest');
    }

    private function audioFile(string $name = 'recording.webm', int $kb = 64, string $mime = 'audio/webm'): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, $mime);
    }

    public function test_guest_cannot_transcribe(): void
    {
        Http::fake();

        $this->postJson(route('voice.transcribe'), [
            'audio' => $this->audioFile(),
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_success_returns_the_transcript_text(): void
    {
        Http::fake([
            'api.mistral.ai/*' => Http::response(['text' => 'Hello from my voice.'], 200),
        ]);

        $user = FixtureLoader::loadMatterFixtures()->user('user-attorney');

        $this->actingAs($user)
            ->postJson(route('voice.transcribe'), [
                'audio' => $this->audioFile(),
            ])
            ->assertOk()
            ->assertExactJson(['text' => 'Hello from my voice.']);

        Http::assertSent(function ($request) {
            // Multipart body: the model field and the file part ride
            // as form parts, so assert on the raw body.
            $body = (string) $request->body();

            return $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer test-mistral-key')
                && str_contains($body, 'name="model"')
                && str_contains($body, 'voxtral-mini-latest')
                && str_contains($body, 'name="file"')
                && str_contains($body, 'recording.webm');
        });
    }

    public function test_missing_key_returns_503_product_line_and_never_calls_mistral(): void
    {
        Config::set('services.mistral.key', null);
        Http::fake();

        $user = FixtureLoader::loadMatterFixtures()->user('user-attorney');

        $this->actingAs($user)
            ->postJson(route('voice.transcribe'), [
                'audio' => $this->audioFile(),
            ])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Transcription isn’t available right now']);

        Http::assertNothingSent();
    }

    public function test_upstream_failure_returns_generic_502(): void
    {
        Http::fake([
            'api.mistral.ai/*' => Http::response(['detail' => 'internal upstream detail'], 500),
        ]);

        $user = FixtureLoader::loadMatterFixtures()->user('user-attorney');

        $response = $this->actingAs($user)
            ->postJson(route('voice.transcribe'), [
                'audio' => $this->audioFile(),
            ])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Transcription isn’t available right now']);

        $this->assertStringNotContainsString('internal upstream detail', $response->getContent());
    }

    public function test_wrong_mime_is_rejected(): void
    {
        Http::fake();

        $user = FixtureLoader::loadMatterFixtures()->user('user-attorney');

        $this->actingAs($user)
            ->postJson(route('voice.transcribe'), [
                'audio' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['audio']]);

        Http::assertNothingSent();
    }

    public function test_oversize_is_rejected(): void
    {
        Http::fake();

        $user = FixtureLoader::loadMatterFixtures()->user('user-attorney');

        $this->actingAs($user)
            ->postJson(route('voice.transcribe'), [
                'audio' => $this->audioFile('recording.webm', 25_700),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['details' => ['audio']]);

        Http::assertNothingSent();
    }
}
