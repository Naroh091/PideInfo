"""
Session manager for the DEHú / RedSARA portal.

DEHú requires both session cookies AND a short-lived Bearer JWT (typically
valid for ~10 minutes) issued by the Angular frontend.  This manager:

- Stores cookies via the standard ``SessionManager`` helper (OS keyring).
- Stores the JWT separately in the OS keyring under ``"dehu:jwt"``.
- Checks JWT expiry client-side (base64-decode payload, inspect ``exp`` claim)
  so no network request is needed to decide whether re-auth is required.
- Triggers a full Playwright re-auth when the JWT is missing or expired.
"""
from __future__ import annotations

import base64
import json
import time
from pathlib import Path

import keyring
from rich.console import Console

from auth.session_manager import SessionManager

console = Console()

_KEYRING_SERVICE = "pideinfo-agent"
_KEYRING_JWT_KEY = "dehu:jwt"


class DehuSessionManager:
    """Manages cookies + Bearer JWT for the DEHú portal."""

    def __init__(
        self,
        portal_url: str,
        cookies_file: Path,
        firefox_profile_dir: Path,
        auth_timeout: int = 120,
    ) -> None:
        self.portal_url = portal_url
        self.firefox_profile_dir = firefox_profile_dir
        self.auth_timeout = auth_timeout
        self._inner = SessionManager(
            portal_url=portal_url,
            cookies_file=cookies_file,
            auth_timeout=auth_timeout,
            firefox_profile_dir=firefox_profile_dir,
        )

    # ------------------------------------------------------------------
    # Cookie access (delegate to inner SessionManager)
    # ------------------------------------------------------------------

    @property
    def cookies(self) -> dict[str, str]:
        return self._inner.cookies

    # ------------------------------------------------------------------
    # JWT management
    # ------------------------------------------------------------------

    @property
    def jwt_token(self) -> str:
        return keyring.get_password(_KEYRING_SERVICE, _KEYRING_JWT_KEY) or ""

    def _save_jwt(self, jwt: str) -> None:
        keyring.set_password(_KEYRING_SERVICE, _KEYRING_JWT_KEY, jwt)

    def jwt_is_valid(self) -> bool:
        """Check JWT expiry client-side — no network needed."""
        token = self.jwt_token
        if not token:
            return False
        try:
            payload_part = token.split(".")[1]
            # Base64url decode (add padding as required)
            padding = (4 - len(payload_part) % 4) % 4
            payload_bytes = base64.urlsafe_b64decode(payload_part + "=" * padding)
            payload = json.loads(payload_bytes)
            exp = payload.get("exp", 0)
            # Require at least 60 seconds of remaining validity
            return exp > time.time() + 60
        except Exception:
            return False

    # ------------------------------------------------------------------
    # Session lifecycle
    # ------------------------------------------------------------------

    async def get_valid_session(self) -> None:
        """
        Ensure both cookies and JWT are valid.

        If the JWT is still valid we assume the cookies are too (they were
        saved together during the same auth session and expire later).

        If the JWT is missing or expired we trigger a full Playwright
        re-authentication which captures fresh cookies and a new JWT.
        """
        if self.jwt_is_valid():
            self._inner.load_cookies()
            console.print("[green]DEHú: sesión JWT válida[/]")
            return

        console.print("[yellow]DEHú: JWT expirado o ausente, re-autenticando...[/]")
        from auth.dehu_auth import authenticate_dehu
        cookies, jwt = await authenticate_dehu(
            portal_url=self.portal_url,
            firefox_profile_dir=self.firefox_profile_dir,
            timeout_seconds=self.auth_timeout,
        )
        self._inner.save_cookies(cookies)
        if jwt:
            self._save_jwt(jwt)
        else:
            console.print("[yellow]DEHú: no se obtuvo JWT — las llamadas API pueden fallar[/]")
