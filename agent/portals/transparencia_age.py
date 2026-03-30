from __future__ import annotations

import json
import re
from pathlib import Path

import httpx
from rich.console import Console

from auth.session_manager import SessionManager, SessionExpiredError
from models.portal import Expediente, Notificacion

console = Console()

# Regex to extract JSON from hidden input elements
RE_EXPEDIENTES_DATA = re.compile(
    r'<input\s+id="expedientesData"\s+type="hidden"\s+value=\'(.*?)\'',
    re.DOTALL,
)
RE_NOTIFICACIONES_DATA = re.compile(
    r'<input\s+id="notificacionesData"\s+type="hidden"\s+value=\'(.*?)\'',
    re.DOTALL,
)
RE_PAGINATION_TOTAL = re.compile(
    r'<dnt-pagination[^>]+total="(\d+)"',
)


class TransparenciaAGEScraper:
    """Scraper for Portal de Transparencia AGE (transparencia.sede.gob.es)."""

    PORTAL_ID = "transparencia_age"

    def __init__(self, session_manager: SessionManager):
        self.session_manager = session_manager
        self.portal_url = session_manager.portal_url
        self._client: httpx.AsyncClient | None = None

    async def _get_client(self) -> httpx.AsyncClient:
        """Get or create an httpx client with current session cookies."""
        if self._client is None or self._client.is_closed:
            cookies = self.session_manager.cookies
            self._client = httpx.AsyncClient(
                cookies=cookies,
                follow_redirects=True,
                timeout=30,
                headers={
                    "User-Agent": (
                        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                        "AppleWebKit/537.36 (KHTML, like Gecko) "
                        "Chrome/131.0.0.0 Safari/537.36"
                    ),
                    "Accept-Language": "es-ES,es;q=0.9",
                    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                },
            )
        return self._client

    async def _get_page(self, path: str) -> str:
        """Fetch a page and check for session expiry."""
        client = await self._get_client()
        response = await client.get(f"{self.portal_url}{path}")
        self.session_manager.check_response_for_expiry(response)
        response.raise_for_status()
        return response.text

    async def get_expedientes(self) -> list[Expediente]:
        """Fetch all expedientes, handling pagination."""
        all_expedientes: list[Expediente] = []
        page = 1

        while True:
            # First page has no param, subsequent pages may use query params
            path = "/privada/expedientes"
            if page > 1:
                path = f"/privada/expedientes?page={page}"

            html = await self._get_page(path)
            expedientes, total = self._parse_expedientes(html)

            if not expedientes:
                break

            all_expedientes.extend(expedientes)

            # Check if we have all items (15 per page)
            if len(all_expedientes) >= total or len(expedientes) < 15:
                break

            page += 1

        console.print(f"[dim]Encontrados {len(all_expedientes)} expedientes[/]")
        return all_expedientes

    async def get_notificaciones(self) -> list[Notificacion]:
        """Fetch all notificaciones, handling pagination."""
        all_notificaciones: list[Notificacion] = []
        page = 1

        while True:
            path = "/privada/notificaciones"
            if page > 1:
                path = f"/privada/notificaciones?page={page}"

            html = await self._get_page(path)
            notificaciones, total = self._parse_notificaciones(html)

            if not notificaciones:
                break

            all_notificaciones.extend(notificaciones)

            if len(all_notificaciones) >= total or len(notificaciones) < 15:
                break

            page += 1

        console.print(f"[dim]Encontradas {len(all_notificaciones)} notificaciones[/]")
        return all_notificaciones

    async def download_document(self, url: str, dest: Path) -> Path:
        """Download a document from the portal."""
        client = await self._get_client()
        dest.parent.mkdir(parents=True, exist_ok=True)

        async with client.stream("GET", f"{self.portal_url}{url}") as response:
            self.session_manager.check_response_for_expiry(response)
            response.raise_for_status()

            with open(dest, "wb") as f:
                async for chunk in response.aiter_bytes(chunk_size=8192):
                    f.write(chunk)

        console.print(f"[dim]Descargado: {dest.name} ({dest.stat().st_size} bytes)[/]")
        return dest

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()

    def _parse_expedientes(self, html: str) -> tuple[list[Expediente], int]:
        """Extract expedientes JSON from hidden input in HTML."""
        match = RE_EXPEDIENTES_DATA.search(html)
        if not match:
            return [], 0

        try:
            raw_json = match.group(1)
            data = json.loads(raw_json)
            expedientes = [Expediente.from_portal_json(item) for item in data]
        except (json.JSONDecodeError, KeyError) as e:
            console.print(f"[red]Error parseando expedientes: {e}[/]")
            return [], 0

        # Extract total from pagination
        total_match = RE_PAGINATION_TOTAL.search(html)
        total = int(total_match.group(1)) if total_match else len(expedientes)

        return expedientes, total

    def _parse_notificaciones(self, html: str) -> tuple[list[Notificacion], int]:
        """Extract notificaciones JSON from hidden input in HTML."""
        match = RE_NOTIFICACIONES_DATA.search(html)
        if not match:
            return [], 0

        try:
            raw_json = match.group(1)
            data = json.loads(raw_json)
            notificaciones = [Notificacion.from_portal_json(item) for item in data]
        except (json.JSONDecodeError, KeyError) as e:
            console.print(f"[red]Error parseando notificaciones: {e}[/]")
            return [], 0

        total_match = RE_PAGINATION_TOTAL.search(html)
        total = int(total_match.group(1)) if total_match else len(notificaciones)

        return notificaciones, total
