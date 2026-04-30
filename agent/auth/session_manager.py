from __future__ import annotations

import json
import os
import time
from pathlib import Path

import httpx
import keyring
from rich.console import Console

from auth.playwright_auth import authenticate, AuthTimeoutError, AuthCancelledError, AuthFailedError

console = Console()

_KEYRING_SERVICE = "pideinfo-agent"


class SessionExpiredError(Exception):
    pass


class SessionManager:
    """Manages portal session cookies: load, save, validate, and re-authenticate."""

    def __init__(
        self,
        portal_url: str,
        cookies_file: Path,
        auth_timeout: int = 120,
        private_path: str = "/privada/expedientes",
        firefox_profile_dir: "Path | None" = None,
        force_headed: bool = False,
    ):
        self.portal_url = portal_url
        self.cookies_file = cookies_file
        self.auth_timeout = auth_timeout
        self.private_path = private_path
        self.firefox_profile_dir = firefox_profile_dir
        self.force_headed = force_headed
        self._cookies: dict[str, str] = {}

    @property
    def cookies(self) -> dict[str, str]:
        return self._cookies

    @property
    def _keyring_key(self) -> str:
        """Unique keyring key derived from the cookies filename."""
        return f"cookies:{self.cookies_file.stem}"

    def load_cookies(self) -> bool:
        """Load cookies from OS keyring. Returns True if cookies were loaded."""
        if not self.cookies_file.exists():
            return False

        try:
            # Metadata (timestamp) is in the file
            data = json.loads(self.cookies_file.read_text())
            saved_at = data.get("saved_at", 0)

            # Cookie values are in the keyring
            raw = keyring.get_password(_KEYRING_SERVICE, self._keyring_key)
            if not raw:
                # Migration: try loading cookies from old plaintext JSON format
                old_cookies = data.get("cookies")
                if old_cookies:
                    self._cookies = old_cookies
                    self.save_cookies(old_cookies)
                    console.print("[dim]Cookies migradas al keyring del sistema[/]")
                    return True
                return False

            self._cookies = json.loads(raw)

            if time.time() - saved_at > 4 * 3600:
                console.print("[dim]Cookies guardadas hace más de 4h, verificando...[/]")

            return bool(self._cookies)
        except (json.JSONDecodeError, KeyError):
            return False

    def save_cookies(self, cookies: dict[str, str]) -> None:
        """Save cookies to OS keyring, metadata to disk."""
        self._cookies = cookies
        self.cookies_file.parent.mkdir(parents=True, exist_ok=True)

        # Cookie values go to the OS keyring
        keyring.set_password(
            _KEYRING_SERVICE, self._keyring_key, json.dumps(cookies)
        )

        # Only metadata on disk (no secrets)
        self.cookies_file.write_text(
            json.dumps({"saved_at": time.time()}, indent=2)
        )
        os.chmod(self.cookies_file, 0o600)
        console.print(f"[dim]Cookies guardadas en keyring ({self._keyring_key})[/]")

    def delete_cookies(self) -> None:
        """Remove cookies from keyring and disk."""
        try:
            keyring.delete_password(_KEYRING_SERVICE, self._keyring_key)
        except keyring.errors.PasswordDeleteError:
            pass
        self.cookies_file.unlink(missing_ok=True)
        self._cookies = {}

    async def is_session_valid(self) -> bool:
        """Check if current cookies give us an authenticated session."""
        if not self._cookies:
            return False

        try:
            async with httpx.AsyncClient(
                cookies=self._cookies,
                follow_redirects=False,
                timeout=30,
            ) as client:
                response = await client.get(f"{self.portal_url}{self.private_path}")

                # If we get 200, session is valid
                # If we get a redirect (302/303) to clave/login, session expired
                if response.status_code == 200:
                    return True

                return False
        except httpx.HTTPError:
            return False

    async def get_valid_session(self) -> dict[str, str]:
        """
        Get valid session cookies. Tries saved cookies first,
        falls back to browser authentication.
        """
        # Try saved cookies
        if self.load_cookies():
            console.print("[dim]Verificando sesión guardada...[/]")
            if await self.is_session_valid():
                console.print("[green]Sesión válida[/]")
                return self._cookies
            console.print("[yellow]Sesión expirada, re-autenticando...[/]")

        # Need fresh authentication
        cookies = await authenticate(
            self.portal_url,
            self.auth_timeout,
            target_path=self.private_path,
            firefox_profile_dir=self.firefox_profile_dir,
            force_headed=self.force_headed,
        )
        self.save_cookies(cookies)
        return cookies

    def check_response_for_expiry(self, response: httpx.Response) -> None:
        """
        Check an HTTP response for signs of session expiry.
        Raises SessionExpiredError if the portal redirected to login.
        """
        # Check if we were redirected to Cl@ve or login page
        final_url = str(response.url)
        if "clave.gob.es" in final_url or "claveproxy" in final_url:
            raise SessionExpiredError("Sesión expirada: redirigido a Cl@ve")

        # Check response history for redirects to login
        for hist in response.history:
            location = hist.headers.get("location", "")
            if "clave" in location.lower() or "authenticate" in location.lower():
                raise SessionExpiredError("Sesión expirada: redirect a login detectado")
