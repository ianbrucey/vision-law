"""Agent tools for the chat slice.

Exactly one tool exists in this slice — the loop-proof tool:

  list_my_matters — read-only. Calls Laravel's internal tool endpoint
  (GET /api/internal/matters) with the shared internal token and the
  turn's user_id; Laravel scopes by Matter::accessibleBy, so the tool
  returns only matters the current user may see (title, number,
  status). No writes anywhere on this path.
"""

from __future__ import annotations

import json
import urllib.parse
import urllib.request
from typing import Any


def list_my_matters(
    *, laravel_internal_url: str, internal_token: str | None, user_id: str
) -> dict[str, Any]:
    """Fetch the user's accessible matters from Laravel.

    Returns {"matters": [...]} on success or {"error": "..."} — never
    raises, so the runner can surface a clean tool error to the model.
    """
    query = urllib.parse.urlencode({"user_id": user_id})
    url = f"{laravel_internal_url}/api/internal/matters?{query}"
    request = urllib.request.Request(url, method="GET")
    request.add_header("Accept", "application/json")
    if internal_token:
        request.add_header("Authorization", f"Bearer {internal_token}")
        request.add_header("X-Internal-Token", internal_token)
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except Exception as exc:  # noqa: BLE001 — tool errors are data here
        return {"error": f"list_my_matters failed: {exc}"}
    if not isinstance(payload, dict):
        return {"error": "list_my_matters failed: unexpected response"}
    return payload


def format_matters(payload: dict[str, Any]) -> str:
    """Plain-text rendering of the tool result for the model / fallback."""
    if "error" in payload:
        return str(payload["error"])
    matters = payload.get("matters") or []
    if not matters:
        return "You have no matters you can access yet."
    lines = ["Your matters:"]
    for matter in matters:
        lines.append(
            f"- {matter.get('number', '')} — {matter.get('title', '')} "
            f"({matter.get('status', '')})"
        )
    return "\n".join(lines)
