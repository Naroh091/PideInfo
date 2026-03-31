from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

import keyring

# Set to True once the portal comparecencia endpoint has been identified
# and implemented in the scraper. Until then the feature does not actually
# accept the notification on the portal and must stay disabled.
ACCEPT_NOTIFICATIONS_AVAILABLE = False

_KEYRING_SERVICE = "pideinfo-agent"


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

    # Client certificate path (reconverted .p12 stored by the agent).
    # Passphrase is stored in the OS keyring, NOT here.
    client_cert_p12: str = ""

    @property
    def is_connected(self) -> bool:
        return bool(self.jwt_token)


def load_preferences(path: Path) -> AgentPreferences:
    """Load preferences from disk, returning defaults if missing or corrupt."""
    if not path.exists():
        return AgentPreferences()

    try:
        data = json.loads(path.read_text())
        prefs = AgentPreferences(
            accept_notifications=bool(data.get("accept_notifications", False)),
            jwt_token=data.get("jwt_token", ""),
            user_email=data.get("user_email", ""),
            user_name=data.get("user_name", ""),
            client_cert_p12=data.get("client_cert_p12", ""),
        )

        # Migrate plaintext passphrase from old format to OS keyring
        old_passphrase = data.get("client_cert_passphrase", "")
        if old_passphrase:
            save_cert_passphrase(old_passphrase)
            save_preferences(prefs, path)

        return prefs
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
            },
            indent=2,
        )
    )
    os.chmod(path, 0o600)


# --- OS keyring helpers for certificate passphrase ---


def save_cert_passphrase(passphrase: str) -> None:
    """Store the certificate passphrase in the OS credential manager."""
    keyring.set_password(_KEYRING_SERVICE, "client_cert_passphrase", passphrase)


def load_cert_passphrase() -> str:
    """Read the certificate passphrase from the OS credential manager."""
    return keyring.get_password(_KEYRING_SERVICE, "client_cert_passphrase") or ""


def delete_cert_passphrase() -> None:
    """Remove the certificate passphrase from the OS credential manager."""
    try:
        keyring.delete_password(_KEYRING_SERVICE, "client_cert_passphrase")
    except keyring.errors.PasswordDeleteError:
        pass
