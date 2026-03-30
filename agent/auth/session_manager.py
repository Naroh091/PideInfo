from __future__ import annotations

import json
import time
from pathlib import Path

import httpx
from rich.console import Console

from auth.playwright_auth import authenticate, AuthTimeoutError, AuthCancelledError, AuthFailedError

console = Console()


class SessionExpiredError(Exception):
    pass


class SessionManager:
    """Manages portal session cookies: load, save, validate, and re-authenticate."""

    def __init__(self, portal_url: str, cookies_file: Path, auth_timeout: int = 120):
        self.portal_url = portal_url
        self.cookies_file = cookies_file
        self.auth_timeout = auth_timeout
        self._cookies: dict[str, str] = {}

    @property
    def cookies(self) -> dict[str, str]:
        return self._cookies

    def load_cookies(self) -> bool:
        """Load cookies from disk. Returns True if cookies were loaded."""
        if not self.cookies_file.exists():
            return False

        try:
            data = json.loads(self.cookies_file.read_text())
            self._cookies = data.get("cookies", {})
            saved_at = data.get("saved_at", 0)

            # Consider cookies stale after 4 hours
            if time.time() - saved_at > 4 * 3600:
                console.print("[dim]Cookies guardadas hace más de 4h, verificando...[/]")

            return bool(self._cookies)
        except (json.JSONDecodeError, KeyError):
            return False

    def save_cookies(self, cookies: dict[str, str]) -> None:
        """Save cookies to disk."""
        self._cookies = cookies
        self.cookies_file.parent.mkdir(parents=True, exist_ok=True)
        self.cookies_file.write_text(
            json.dumps(
                {"cookies": cookies, "saved_at": time.time()},
                indent=2,
            )
        )
        console.print(f"[dim]Cookies guardadas en {self.cookies_file}[/]")

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
                response = await client.get(f"{self.portal_url}/privada/expedientes")

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
        cookies = await authenticate(self.portal_url, self.auth_timeout)
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
