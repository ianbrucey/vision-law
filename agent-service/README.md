# Vision Law — agent service (Spec 012 chat slice)

Private Python HTTP service behind the Laravel front-desk chat. It
binds **127.0.0.1 only** and is never exposed publicly: Laravel is its
only client, and every call carries the shared internal token
(`AGENT_INTERNAL_TOKEN`, server env only).

## Endpoints

- `GET /health` → `{ok, runner, configured}`. `configured` is false
  until a provider key exists in the service env — the service still
  boots and serves in that state.
- `POST /turn` → NDJSON event stream. Request:
  `{session_id, message, user_id, org_id, history, context}`.
  Events: `token` / `tool` / `done` / `error`. With no provider key,
  a turn returns one labeled `agent_not_configured` error event.

## The swap seam

`runners.py` defines `AgentRunner.run_turn(session_id, message,
context) -> stream of events`:

- `ClaudeAgentRunner` — Python `claude-agent-sdk` (`ClaudeSDKClient`).
  Provider/model are **env only**, passed via
  `ClaudeAgentOptions(env=...)` from the service env
  (`ANTHROPIC_BASE_URL`, `ANTHROPIC_AUTH_TOKEN`, `ANTHROPIC_MODEL`,
  the default opus/sonnet/haiku + subagent model vars). DeepSeek's
  Anthropic-compatible endpoint, GLM, or Anthropic itself differ only
  in those values.
- `GoogleAdkRunner` — stub implementing the same interface; yields a
  clear not-implemented error. The future ADK swap writes this class
  and touches nothing in Laravel.

Select with `AGENT_RUNNER=claude|google_adk`.

## Tools

One read-only loop-proof tool: `list_my_matters`, implemented as an
in-process SDK MCP tool that calls Laravel's internal endpoint
`GET /api/internal/matters` with the internal token. Laravel scopes by
`Matter::accessibleBy` — the tool sees only what the user can.

System prompt: minimal front-desk persona (Ross — read-only
reception: rollups/triage/routing, no case writes), in `runners.py`.

## Run

Installed as the systemd unit `visionlaw-agent.service`
(`deploy/visionlaw-agent.service` in the repo) with its env in
`/opt/vision-law/agent-service/agent.env` (not in git). The unit runs
the service venv (`agent-service/.venv`, gitignored) so the
`claude-agent-sdk` install is importable; rebuild it with
`python3 -m venv agent-service/.venv && agent-service/.venv/bin/pip install -r agent-service/requirements.txt`:

```sh
systemctl status visionlaw-agent
python3 smoke_test.py   # boots an unconfigured instance and checks it
```

The HTTP layer is stdlib-only; `claude-agent-sdk` (see
`requirements.txt`) is imported lazily so the service boots — and the
unconfigured path stays testable — before the SDK/key land.
