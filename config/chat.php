<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spec 012 — Chat slice (front desk)
    |--------------------------------------------------------------------------
    |
    | live_enabled: master flag for the real agent-backed front-desk chat.
    | When false, the chat endpoints report sample mode and frontdesk.js
    | keeps the labeled preview behaviour — nothing is faked as live.
    |
    | agent_url: base URL of the private agent service (loopback only).
    | The service is never exposed publicly; Laravel is its only client.
    |
    | internal_token: shared secret between Laravel and the agent service,
    | in both directions (Laravel -> agent turn calls, agent -> Laravel
    | tool calls). Server-side .env only; never committed.
    |
    | agent_timeout: seconds before a turn with no completion is aborted.
    */

    'live_enabled' => (bool) env('CHAT_LIVE_ENABLED', false),

    'agent_url' => rtrim((string) env('AGENT_SERVICE_URL', 'http://127.0.0.1:8100'), '/'),

    'internal_token' => env('AGENT_INTERNAL_TOKEN'),

    'agent_timeout' => (int) env('AGENT_SERVICE_TIMEOUT', 120),

];
