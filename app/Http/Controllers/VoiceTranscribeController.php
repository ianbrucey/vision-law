<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spec 012 — voice mode transcription (Ian, 2026-11-05).
 *
 * POST /voice/transcribe accepts ONE finished recording from either
 * chat composer (front desk or matter workspace), forwards it to
 * Mistral's audio transcription API, and returns {text}. Local-first
 * by design: the browser holds the audio until the user presses stop,
 * and this endpoint is the only upload.
 *
 * The audio is processed transiently — the multipart body is read from
 * the upload's temp path and forwarded; nothing is written to disk by
 * this controller, nothing is stored, and neither the audio nor the
 * transcript is persisted or logged here (the composer/message flow
 * owns persistence). User-facing failures are product lines only; the
 * upstream detail goes to the log (no-meta-text rule, 2026-11-05).
 */
class VoiceTranscribeController extends Controller
{
    private const UNAVAILABLE_MESSAGE = 'Transcription isn’t available right now';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => [
                'required',
                'file',
                // MediaRecorder output: webm/opus (Chrome, Firefox),
                // ogg (older Firefox), mp4/m4a (Safari); mpeg/wav for
                // completeness. Cap 25 MB.
                'mimetypes:audio/webm,video/webm,audio/ogg,video/ogg,audio/mp4,video/mp4,audio/mpeg,audio/wav,audio/x-wav,audio/x-m4a',
                'max:25600',
            ],
        ]);

        $key = config('services.mistral.key');
        if (! is_string($key) || trim($key) === '') {
            return response()->json(['message' => self::UNAVAILABLE_MESSAGE], 503);
        }

        $file = $request->file('audio');

        try {
            $response = Http::withToken($key)
                ->timeout(120)
                ->attach(
                    'file',
                    (string) file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName() ?: 'recording.webm',
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                )
                ->post('https://api.mistral.ai/v1/audio/transcriptions', [
                    'model' => config('services.mistral.transcribe_model'),
                ]);
        } catch (Throwable $e) {
            Log::warning('Voice transcription request failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => self::UNAVAILABLE_MESSAGE], 502);
        }

        $text = $response->json('text');
        if (! $response->successful() || ! is_string($text)) {
            Log::warning('Voice transcription upstream error', [
                'status' => $response->status(),
            ]);

            return response()->json(['message' => self::UNAVAILABLE_MESSAGE], 502);
        }

        return response()->json(['text' => $text]);
    }
}
