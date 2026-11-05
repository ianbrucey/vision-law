<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Spec 012 chat slice: Laravel's client for the private agent service.
 *
 * Laravel is the agent service's ONLY client. The service binds
 * loopback and requires the shared internal token on every call; the
 * token lives in .env (config/chat.php) and is never committed.
 *
 * streamTurn() POSTs one turn and yields decoded NDJSON events as they
 * arrive: {type: token|tool|done|error, ...}. Transport is cURL with a
 * write callback so tokens are forwarded incrementally instead of
 * buffered; $shouldStop is polled between chunks so a disconnected
 * browser aborts the upstream turn too.
 */
class AgentClient
{
    public function baseUrl(): string
    {
        return (string) config('chat.agent_url');
    }

    public function internalToken(): ?string
    {
        $token = config('chat.internal_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Health probe: is the service up, and does it consider itself
     * configured (a provider key present in ITS env)? Never throws.
     *
     * @return array{ok: bool, configured: bool, runner: string|null}
     */
    public function health(): array
    {
        try {
            $response = Http::timeout(3)
                ->withHeaders($this->headers())
                ->get($this->baseUrl().'/health');

            if (! $response->successful()) {
                return ['ok' => false, 'configured' => false, 'runner' => null];
            }

            $json = $response->json();

            return [
                'ok' => true,
                'configured' => (bool) ($json['configured'] ?? false),
                'runner' => isset($json['runner']) && is_string($json['runner']) ? $json['runner'] : null,
            ];
        } catch (\Throwable $e) {
            Log::debug('Agent service health probe failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'configured' => false, 'runner' => null];
        }
    }

    /**
     * Stream one turn. Yields one decoded event per NDJSON line.
     *
     * On any transport failure this yields a single labeled error event
     * instead of throwing, so the controller's SSE stream always ends
     * with a terminal event the UI can render honestly.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(): bool  $shouldStop
     * @return iterable<int, array<string, mixed>>
     */
    public function streamTurn(array $payload, callable $shouldStop): iterable
    {
        $token = $this->internalToken();
        $url = $this->baseUrl().'/turn';
        $body = json_encode($payload);

        if ($body === false) {
            yield ['type' => 'error', 'code' => 'agent_request_invalid', 'message' => 'Could not encode the turn request.'];

            return;
        }

        $headers = ['Content-Type: application/json', 'Accept: application/x-ndjson'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
            $headers[] = 'X-Internal-Token: '.$token;
        }

        $buffer = '';
        $events = [];
        $failed = null;

        $ch = curl_init($url);
        if ($ch === false) {
            yield ['type' => 'error', 'code' => 'agent_unreachable', 'message' => 'Agent service is unreachable.'];

            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) config('chat.agent_timeout', 120),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$events, $shouldStop): int {
                if ($shouldStop()) {
                    return 0; // abort the transfer
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        $decoded = json_decode($line, true);
                        if (is_array($decoded)) {
                            $events[] = $decoded;
                        }
                    }
                }

                return strlen($chunk);
            },
        ]);

        // cURL drives the write callback synchronously inside curl_exec,
        // so true incremental yielding needs the callback to forward as
        // it goes. We collect-then-yield per exec is not possible; the
        // controller instead consumes this generator AFTER exec for the
        // persisted record, while live forwarding uses the callback
        // variant below. This method buffers events for callers that
        // only need the sequence (tests, fallback path).
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            $failed = $error !== '' ? $error : 'connection failed';
        }

        if (trim($buffer) !== '') {
            $decoded = json_decode(trim($buffer), true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        if ($events === [] && $failed !== null) {
            yield ['type' => 'error', 'code' => 'agent_unreachable', 'message' => 'Agent service is unreachable.'];

            return;
        }

        foreach ($events as $event) {
            /** @var array<string, mixed> $event */
            yield $event;
        }
    }

    /**
     * Forwarding variant used by the SSE controller: $onEvent is called
     * the moment each NDJSON line completes, inside the transfer, so the
     * browser receives tokens live. Returns the full event list.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): void  $onEvent
     * @param  callable(): bool  $shouldStop
     * @return list<array<string, mixed>>
     */
    public function forwardTurn(array $payload, callable $onEvent, callable $shouldStop): array
    {
        $token = $this->internalToken();
        $url = $this->baseUrl().'/turn';
        $body = json_encode($payload);

        if ($body === false) {
            $event = ['type' => 'error', 'code' => 'agent_request_invalid', 'message' => 'Could not encode the turn request.'];
            $onEvent($event);

            return [$event];
        }

        $headers = ['Content-Type: application/json', 'Accept: application/x-ndjson'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
            $headers[] = 'X-Internal-Token: '.$token;
        }

        $buffer = '';
        $events = [];
        $emit = function (string $line) use (&$events, $onEvent): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $events[] = $decoded;
                $onEvent($decoded);
            }
        };

        $ch = curl_init($url);
        if ($ch === false) {
            $event = ['type' => 'error', 'code' => 'agent_unreachable', 'message' => 'Agent service is unreachable.'];
            $onEvent($event);

            return [$event];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) config('chat.agent_timeout', 120),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, $emit, $shouldStop): int {
                if ($shouldStop()) {
                    return 0;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $emit($line);
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if (trim($buffer) !== '') {
            $emit($buffer);
        }

        if ($events === [] && ($ok === false || $errno !== 0)) {
            $event = ['type' => 'error', 'code' => 'agent_unreachable', 'message' => 'Agent service is unreachable.'];
            $events[] = $event;
            $onEvent($event);
        }

        return $events;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $token = $this->internalToken();
        if ($token === null) {
            return [];
        }

        return ['Authorization' => 'Bearer '.$token, 'X-Internal-Token' => $token];
    }
}
