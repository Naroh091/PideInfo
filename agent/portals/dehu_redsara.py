"""
Scraper for the DEHú (Dirección Electrónica Habilitada única) portal at
https://dehu.redsara.es

DEHú exposes a JSON REST API.  Requests require both the session cookies
(set on the httpx client) and a short-lived ``Authorization: Bearer <jwt>``
header managed by ``DehuSessionManager``.
"""
from __future__ import annotations

from pathlib import Path

import httpx
import truststore
from rich.console import Console

from auth.dehu_session_manager import DehuSessionManager
from models.dehu import DehuNotificacion

truststore.inject_into_ssl()

console = Console()


class DehuScraper:
    """Client for the DEHú REST API."""

    PORTAL_ID = "dehu_redsara"

    def __init__(self, session_manager: DehuSessionManager) -> None:
        self.session_manager = session_manager
        self._base_url = session_manager.portal_url.rstrip("/")
        self._client: httpx.AsyncClient | None = None

    async def _get_client(self) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            self._client = httpx.AsyncClient(
                cookies=self.session_manager.cookies,
                follow_redirects=True,
                verify=True,
                timeout=30,
                headers={
                    "User-Agent": (
                        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) "
                        "Gecko/20100101 Firefox/133.0"
                    ),
                    "Accept-Language": "es-ES,es;q=0.9",
                    "Accept": "application/json",
                },
            )
        return self._client

    async def get_notificaciones(
        self,
        page: int = 1,
        limit: int = 50,
    ) -> list[DehuNotificacion]:
        """Fetch notifications from GET /api/v1/notifications?"""
        client = await self._get_client()
        response = await client.get(
            f"{self._base_url}/api/v1/notifications",
            params={"page": page, "limit": limit},
            headers={
                "Authorization": f"Bearer {self.session_manager.jwt_token}",
                "Accept": "application/json",
                "Accept-Language": "es",
            },
        )
        response.raise_for_status()
        data = response.json()

        # API returns either a list directly or a paginated envelope {"content": [...]}
        if isinstance(data, list):
            items = data
        else:
            items = data.get("content", data.get("items", []))

        notificaciones = [DehuNotificacion.from_api_dict(item) for item in items]
        console.print(f"[dim]DEHú: encontradas {len(notificaciones)} notificaciones[/]")
        return notificaciones

    async def accept_and_download(self, sent_reference: str) -> Path:
        """Phase 2 stub — DEHú document download not yet implemented."""
        raise NotImplementedError(
            "DEHú document download will be implemented in Phase 2. "
            f"Notification: {sent_reference}"
        )

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()
