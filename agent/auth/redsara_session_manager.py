"""
Session manager for Red SARA REC (Registro Electrónico Común).

Same pattern as DEHú: session cookies + short-lived Bearer JWT (~1 hour).
JWT is stored in the OS keyring and checked client-side for expiry.
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
_KEYRING_JWT_KEY = "redsara:jwt"           # RS256 ROLE_API — search/list calls
_KEYRING_DOWNLOAD_JWT_KEY = "redsara:download_jwt"  # HS256 ROLE_USER — document downloads


class RedSaraSessionManager:
    """Manages cookies + Bearer JWT for the Red SARA REC portal."""

    def __init__(
        self,
        portal_url: str,
        cookies_file: Path,
        firefox_profile_dir: Path,
        auth_timeout: int = 120,
        headless: bool = True,
    ) -> None:
        self.portal_url = portal_url
        self.firefox_profile_dir = firefox_profile_dir
        self.auth_timeout = auth_timeout
        self.headless = headless
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
        """RS256 ROLE_API token — used for search/list/detail API calls."""
        return keyring.get_password(_KEYRING_SERVICE, _KEYRING_JWT_KEY) or ""

    @property
    def download_jwt_token(self) -> str:
        """HS256 ROLE_USER token — used for document download calls."""
        return keyring.get_password(_KEYRING_SERVICE, _KEYRING_DOWNLOAD_JWT_KEY) or ""

    def _save_jwt(self, jwt: str) -> None:
        keyring.set_password(_KEYRING_SERVICE, _KEYRING_JWT_KEY, jwt)

    def _save_download_jwt(self, jwt: str) -> None:
        keyring.set_password(_KEYRING_SERVICE, _KEYRING_DOWNLOAD_JWT_KEY, jwt)

    @staticmethod
    def _check_jwt_expiry(token: str, min_remaining: int = 60) -> bool:
        """Return True if the token exists and has at least ``min_remaining`` seconds left."""
        if not token:
            return False
        try:
            payload_part = token.split(".")[1]
            padding = (4 - len(payload_part) % 4) % 4
            payload_bytes = base64.urlsafe_b64decode(payload_part + "=" * padding)
            payload = json.loads(payload_bytes)
            exp = payload.get("exp", 0)
            return exp > time.time() + min_remaining
        except Exception:
            return False

    def jwt_is_valid(self) -> bool:
        """Check API JWT expiry client-side — no network needed."""
        return self._check_jwt_expiry(self.jwt_token)

    def download_jwt_is_valid(self) -> bool:
        """Check document download JWT expiry client-side — no network needed."""
        return self._check_jwt_expiry(self.download_jwt_token)

    # ------------------------------------------------------------------
    # Session lifecycle
    # ------------------------------------------------------------------

    async def get_valid_session(self) -> None:
        """
        Ensure both cookies and both JWTs (search + download) are valid.

        If both JWTs are still valid we assume the cookies are too (they were
        saved together during the same auth session and expire later).
        """
        if self.jwt_is_valid() and self.download_jwt_is_valid():
            self._inner.load_cookies()
            console.print("[green]Red SARA: sesión JWT válida[/]")
            return

        console.print("[yellow]Red SARA: JWT expirado o ausente, re-autenticando...[/]")
        from auth.redsara_auth import authenticate_redsara
        cookies, jwt, download_jwt = await authenticate_redsara(
            portal_url=self.portal_url,
            firefox_profile_dir=self.firefox_profile_dir,
            timeout_seconds=self.auth_timeout,
            headless=self.headless,
        )
        self._inner.save_cookies(cookies)
        if jwt:
            self._save_jwt(jwt)
        else:
            console.print("[yellow]Red SARA: no se obtuvo JWT de búsqueda — las llamadas API pueden fallar[/]")
        if download_jwt:
            self._save_download_jwt(download_jwt)
        else:
            console.print("[yellow]Red SARA: no se obtuvo JWT de descarga — las descargas fallarán[/]")
