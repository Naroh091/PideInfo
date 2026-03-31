from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path


# Set to True once the portal comparecencia endpoint has been identified
# and implemented in the scraper. Until then the feature does not actually
# accept the notification on the portal and must stay disabled.
ACCEPT_NOTIFICATIONS_AVAILABLE = False


@dataclass
class AgentPreferences:
    """User-configurable agent settings persisted to disk."""

    # When True: PENDIENTE notifications are downloaded (= accepted) automatically.
    accept_notifications: bool = False

    # JWT token for PideInfo API authentication
    jwt_token: str = ""

    # Cached user info from PideInfo (populated on connection)
    user_email: str = ""
    user_name: str = ""

    # Client certificate (reconverted .p12 stored by the agent)
    client_cert_p12: str = ""
    client_cert_passphrase: str = ""

    @property
    def is_connected(self) -> bool:
        return bool(self.jwt_token)


def load_preferences(path: Path) -> AgentPreferences:
    """Load preferences from disk, returning defaults if missing or corrupt."""
    if not path.exists():
        return AgentPreferences()

    try:
        data = json.loads(path.read_text())
        return AgentPreferences(
            accept_notifications=bool(data.get("accept_notifications", False)),
            jwt_token=data.get("jwt_token", ""),
            user_email=data.get("user_email", ""),
            user_name=data.get("user_name", ""),
            client_cert_p12=data.get("client_cert_p12", ""),
            client_cert_passphrase=data.get("client_cert_passphrase", ""),
        )
    except (json.JSONDecodeError, KeyError):
        return AgentPreferences()


def save_preferences(prefs: AgentPreferences, path: Path) -> None:
    """Persist preferences to disk."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "accept_notifications": prefs.accept_notifications,
                "jwt_token": prefs.jwt_token,
                "user_email": prefs.user_email,
                "user_name": prefs.user_name,
                "client_cert_p12": prefs.client_cert_p12,
                "client_cert_passphrase": prefs.client_cert_passphrase,
            },
            indent=2,
        )
    )
