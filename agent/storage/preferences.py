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
_KEYRING_PIDEINFO_JWT_KEY = "pideinfo:jwt"


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

    # Optional override for the PideInfo backend URL.
    # Empty string = use the default from config / .env.
    pideinfo_base_url: str = ""

    # Portal sync toggles — all enabled by default
    sync_transparencia: bool = True
    sync_ctbg: bool = True
    sync_dehu: bool = True
    sync_redsara: bool = True

    # Debug: launch browsers in headed mode (visible window)
    headless_disabled: bool = False

    @property
    def is_connected(self) -> bool:
        return bool(self.jwt_token)


def load_preferences(path: Path) -> AgentPreferences:
    """Load preferences from disk, returning defaults if missing or corrupt."""
    if not path.exists():
        return AgentPreferences()

    try:
        data = json.loads(path.read_text())

        # Load JWT from keyring; migrate from plaintext JSON if still there
        jwt_token = keyring.get_password(_KEYRING_SERVICE, _KEYRING_PIDEINFO_JWT_KEY) or ""
        if not jwt_token and data.get("jwt_token"):
            jwt_token = data["jwt_token"]
            keyring.set_password(_KEYRING_SERVICE, _KEYRING_PIDEINFO_JWT_KEY, jwt_token)

        prefs = AgentPreferences(
            accept_notifications=bool(data.get("accept_notifications", False)),
            jwt_token=jwt_token,
            user_email=data.get("user_email", ""),
            user_name=data.get("user_name", ""),
            pideinfo_base_url=data.get("pideinfo_base_url", ""),
            sync_transparencia=bool(data.get("sync_transparencia", True)),
            sync_ctbg=bool(data.get("sync_ctbg", True)),
            sync_dehu=bool(data.get("sync_dehu", True)),
            sync_redsara=bool(data.get("sync_redsara", True)),
            headless_disabled=bool(data.get("headless_disabled", False)),
        )

        # Migration: remove stale cert artifacts left by older versions of the agent
        _migrate_remove_cert_artifacts(data, path)

        return prefs
    except (json.JSONDecodeError, KeyError):
        return AgentPreferences()


def _migrate_remove_cert_artifacts(data: dict, prefs_path: Path) -> None:
    """One-time cleanup of .p12 files and keyring entries from older agent versions."""
    # Remove converted .p12 from disk if it was stored by an older version
    old_cert_path = data.get("client_cert_p12", "")
    if old_cert_path:
        try:
            Path(old_cert_path).unlink(missing_ok=True)
        except OSError:
            pass

    # Remove passphrase from keyring if it was stored by an older version
    try:
        keyring.delete_password(_KEYRING_SERVICE, "client_cert_passphrase")
    except Exception:
        pass

    # Rewrite preferences without the cert fields and without the plaintext JWT
    # (JWT now lives in the OS keyring, cert fields are deprecated)
    if "client_cert_p12" in data or "client_cert_passphrase" in data or "jwt_token" in data:
        save_preferences(
            AgentPreferences(
                accept_notifications=bool(data.get("accept_notifications", False)),
                jwt_token=data.get("jwt_token", ""),
                user_email=data.get("user_email", ""),
                user_name=data.get("user_name", ""),
            ),
            prefs_path,
        )


def save_preferences(prefs: AgentPreferences, path: Path) -> None:
    """Persist preferences to disk and JWT token to the OS keyring."""
    path.parent.mkdir(parents=True, exist_ok=True)

    # JWT goes to the OS keyring — never to disk
    if prefs.jwt_token:
        keyring.set_password(_KEYRING_SERVICE, _KEYRING_PIDEINFO_JWT_KEY, prefs.jwt_token)
    else:
        try:
            keyring.delete_password(_KEYRING_SERVICE, _KEYRING_PIDEINFO_JWT_KEY)
        except Exception:
            pass

    # Non-secret metadata stays on disk (no token)
    path.write_text(
        json.dumps(
            {
                "accept_notifications": prefs.accept_notifications,
                "user_email": prefs.user_email,
                "user_name": prefs.user_name,
                "pideinfo_base_url": prefs.pideinfo_base_url,
                "sync_transparencia": prefs.sync_transparencia,
                "sync_ctbg": prefs.sync_ctbg,
                "sync_dehu": prefs.sync_dehu,
                "sync_redsara": prefs.sync_redsara,
                "headless_disabled": prefs.headless_disabled,
            },
            indent=2,
        )
    )
    os.chmod(path, 0o600)
