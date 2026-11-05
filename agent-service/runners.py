"""AgentRunner — the runtime swap seam (chat-slice plan, O-12-2).

Laravel and the chat UI talk to ONE interface:

    run_turn(session_id, message, context) -> stream of events

Events are dicts: {"type": "token", "text": str}
                  {"type": "tool", "name": str, ...}
                  {"type": "done", "text": str}
                  {"type": "error", "code": str, "message": str}

Implementations:
  ClaudeAgentRunner — claude-agent-sdk (ClaudeSDKClient). Provider and
    model arrive ONLY through ClaudeAgentOptions(env=...) built from
    the service env; nothing about a provider is hardcoded. If the SDK
    is not installed or no provider key is configured, the runner
    yields a clean, labeled "agent not configured" error event — the
    service still boots and serves health.
  GoogleAdkRunner — STUB. Same interface; raises/yields a clear
    not-implemented error. Writing this class against the interface is
    the entire future ADK swap; Laravel never changes.
"""

from __future__ import annotations

import asyncio
from abc import ABC, abstractmethod
from typing import Any, AsyncIterator

from config import Settings
from tools import format_matters, list_my_matters

FRONT_DESK_SYSTEM_PROMPT = """You are Ross, the front-desk agent for Vision Law.

You are read-only reception: you help the user see across their own
matters — rollups, triage, what is due, what is waiting on them — and
you route them into a matter when they ask. You cannot open a case
file, change anything, draft, or file. When a request needs case work,
say so plainly and offer to route the user into that matter's
workspace instead.

You have one tool in this slice: list_my_matters, which returns the
matters the current user can access (title, number, status). Use it
whenever the user asks about their matters. Never invent matters,
deadlines, or case facts that did not come from a tool result or the
user. Keep answers short and concrete."""


def not_configured_event(reason: str) -> dict[str, Any]:
    return {
        "type": "error",
        "code": "agent_not_configured",
        "message": f"Agent not configured: {reason}",
    }


class AgentRunner(ABC):
    """The swap seam. One turn in, an event stream out."""

    name: str = "base"

    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    @property
    @abstractmethod
    def configured(self) -> bool:
        """True when this runner can actually serve a turn."""

    @abstractmethod
    async def run_turn(
        self,
        session_id: str,
        message: str,
        context: dict[str, Any],
    ) -> AsyncIterator[dict[str, Any]]:
        """Yield token/tool/done/error events for one turn."""
        yield {}  # pragma: no cover — abstract
        raise NotImplementedError


class ClaudeAgentRunner(AgentRunner):
    """Claude Agent SDK runner. Provider/model are env only."""

    name = "claude"

    def __init__(self, settings: Settings) -> None:
        super().__init__(settings)
        self._sdk_error: str | None = None
        try:
            import claude_agent_sdk  # noqa: F401
        except Exception as exc:  # noqa: BLE001 — missing SDK is a state, not a crash
            self._sdk_error = f"claude-agent-sdk is not installed ({exc})"

    @property
    def configured(self) -> bool:
        return self._sdk_error is None and self.settings.provider_configured

    @property
    def unconfigured_reason(self) -> str:
        if self._sdk_error is not None:
            return self._sdk_error
        if not self.settings.provider_configured:
            return (
                "no provider key in the agent service env "
                "(ANTHROPIC_AUTH_TOKEN / ANTHROPIC_API_KEY is empty). "
                "Add one via secure entry on the server and restart "
                "visionlaw-agent.service."
            )
        return "unknown"

    async def run_turn(
        self,
        session_id: str,
        message: str,
        context: dict[str, Any],
    ) -> AsyncIterator[dict[str, Any]]:
        if not self.configured:
            yield not_configured_event(self.unconfigured_reason)
            return

        # Imports stay lazy so the module loads (and health serves)
        # even when the SDK is absent.
        from claude_agent_sdk import (  # type: ignore[import-not-found]
            ClaudeAgentOptions,
            ClaudeSDKClient,
            create_sdk_mcp_server,
            tool,
        )

        settings = self.settings
        user_id = str(context.get("user_id") or "")

        @tool(
            "list_my_matters",
            "List the matters the current user can access "
            "(title, number, status). Read-only.",
            {},
        )
        async def _list_my_matters(args: dict[str, Any]) -> dict[str, Any]:
            payload = await asyncio.to_thread(
                list_my_matters,
                laravel_internal_url=settings.laravel_internal_url,
                internal_token=settings.internal_token,
                user_id=user_id,
            )
            return {
                "content": [
                    {"type": "text", "text": format_matters(payload)}
                ]
            }

        brain_tools = create_sdk_mcp_server(
            name="visionlaw",
            version="0.1.0",
            tools=[_list_my_matters],
        )

        options = ClaudeAgentOptions(
            system_prompt=FRONT_DESK_SYSTEM_PROMPT,
            mcp_servers={"visionlaw": brain_tools},
            allowed_tools=["mcp__visionlaw__list_my_matters"],
            env=dict(settings.provider_env),
            resume=session_id or None,
        )

        full_text = ""
        try:
            async with ClaudeSDKClient(options=options) as client:
                await client.query(message)
                async for sdk_message in client.receive_response():
                    for event in self._events_from_sdk_message(sdk_message):
                        if event.get("type") == "token":
                            full_text += str(event.get("text", ""))
                        yield event
        except Exception as exc:  # noqa: BLE001 — surface, never crash the stream
            yield {
                "type": "error",
                "code": "agent_turn_failed",
                "message": f"The agent turn failed: {exc}",
            }
            return

        yield {"type": "done", "text": full_text}

    @staticmethod
    def _events_from_sdk_message(sdk_message: Any) -> list[dict[str, Any]]:
        """Map SDK message blocks to our token/tool events.

        Defensive by design: unknown block shapes are ignored rather
        than crashing the stream.
        """
        events: list[dict[str, Any]] = []
        content = getattr(sdk_message, "content", None)
        if isinstance(content, str):
            if content:
                events.append({"type": "token", "text": content})
            return events
        if not isinstance(content, list):
            return events
        for block in content:
            text = getattr(block, "text", None)
            if isinstance(text, str) and text:
                events.append({"type": "token", "text": text})
                continue
            name = getattr(block, "name", None)
            if isinstance(name, str) and name:
                events.append({"type": "tool", "name": name})
        return events


class GoogleAdkRunner(AgentRunner):
    """STUB — the future ADK swap target. Same interface, not built."""

    name = "google_adk"

    @property
    def configured(self) -> bool:
        return False

    async def run_turn(
        self,
        session_id: str,
        message: str,
        context: dict[str, Any],
    ) -> AsyncIterator[dict[str, Any]]:
        yield {
            "type": "error",
            "code": "runner_not_implemented",
            "message": (
                "Google ADK runner is not implemented yet — it is a "
                "stub behind the AgentRunner interface. Set "
                "AGENT_RUNNER=claude to use the Claude Agent SDK."
            ),
        }
        return
        yield {}  # pragma: no cover — unreachable; keeps this a generator


def make_runner(settings: Settings) -> AgentRunner:
    if settings.runner in ("google", "google_adk", "adk"):
        return GoogleAdkRunner(settings)
    return ClaudeAgentRunner(settings)
