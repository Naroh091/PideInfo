from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path


@dataclass
class AgentPreferences:
    """User-configurable agent settings persisted to disk."""

    # When True: PENDIENTE notifications are downloaded (= accepted) automatically.
    accept_notifications: bool = False


def load_preferences(path: Path) -> AgentPreferences:
    """Load preferences from disk, returning defaults if missing or corrupt."""
    if not path.exists():
        return AgentPreferences()

    try:
        data = json.loads(path.read_text())
        return AgentPreferences(
            accept_notifications=bool(data.get("accept_notifications", False)),
        )
    except (json.JSONDecodeError, KeyError):
        return AgentPreferences()


def save_preferences(prefs: AgentPreferences, path: Path) -> None:
    """Persist preferences to disk."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {"accept_notifications": prefs.accept_notifications},
            indent=2,
        )
    )
