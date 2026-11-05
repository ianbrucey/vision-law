"""Vision Law agent service — configuration.

Provider/model selection is ENV ONLY (chat-slice plan, O-12-2): the
Claude Agent SDK honours ANTHROPIC_BASE_URL / ANTHROPIC_AUTH_TOKEN /
ANTHROPIC_MODEL, so switching between Anthropic, DeepSeek's
Anthropic-compatible endpoint, or GLM is an env change, never a code
change. Values live in the service's own env file on the server
(systemd EnvironmentFile), never in git.

Expected env vars (names only — see .env.example):
  AGENT_PORT, AGENT_RUNNER, AGENT_INTERNAL_TOKEN,
  LARAVEL_INTERNAL_URL,
  ANTHROPIC_BASE_URL, ANTHROPIC_AUTH_TOKEN, ANTHROPIC_API_KEY,
  ANTHROPIC_MODEL, ANTHROPIC_DEFAULT_OPUS_MODEL,
  ANTHROPIC_DEFAULT_SONNET_MODEL, ANTHROPIC_DEFAULT_HAIKU_MODEL,
  CLAUDE_CODE_SUBAGENT_MODEL
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field

# Env vars forwarded to the Claude Agent SDK process via
# ClaudeAgentOptions(env=...). Provider is env only, never hardcoded.
PROVIDER_ENV_KEYS = (
    "ANTHROPIC_BASE_URL",
    "ANTHROPIC_AUTH_TOKEN",
    "ANTHROPIC_API_KEY",
    "ANTHROPIC_MODEL",
    "ANTHROPIC_DEFAULT_OPUS_MODEL",
    "ANTHROPIC_DEFAULT_SONNET_MODEL",
    "ANTHROPIC_DEFAULT_HAIKU_MODEL",
    "CLAUDE_CODE_SUBAGENT_MODEL",
)


@dataclass
class Settings:
    host: str = "127.0.0.1"
    port: int = 8100
    runner: str = "claude"
    internal_token: str | None = None
    laravel_internal_url: str = "http://127.0.0.1:3100"
    provider_env: dict[str, str] = field(default_factory=dict)

    @property
    def provider_configured(self) -> bool:
        """A provider key is present (token or API key)."""
        return bool(
            self.provider_env.get("ANTHROPIC_AUTH_TOKEN")
            or self.provider_env.get("ANTHROPIC_API_KEY")
        )

    @classmethod
    def from_env(cls) -> "Settings":
        provider_env = {
            key: os.environ[key] for key in PROVIDER_ENV_KEYS if os.environ.get(key)
        }
        return cls(
            host=os.environ.get("AGENT_HOST", "127.0.0.1"),
            port=int(os.environ.get("AGENT_PORT", "8100")),
            runner=os.environ.get("AGENT_RUNNER", "claude").strip().lower(),
            internal_token=os.environ.get("AGENT_INTERNAL_TOKEN") or None,
            laravel_internal_url=os.environ.get(
                "LARAVEL_INTERNAL_URL", "http://127.0.0.1:3100"
            ).rstrip("/"),
            provider_env=provider_env,
        )
