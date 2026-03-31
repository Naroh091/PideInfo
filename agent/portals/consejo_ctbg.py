from __future__ import annotations

from bs4 import BeautifulSoup
import httpx
from rich.console import Console

from auth.session_manager import SessionManager
from models.consejo import ConsejoNotificacion

console = Console()


class ConsejoScraper:
    """Scraper for CTBG sede electrónica (Wicket-based HTML tables)."""

    PORTAL_ID = "consejo_ctbg"

    def __init__(self, session_manager: SessionManager):
        self.session_manager = session_manager
        self.portal_url = session_manager.portal_url
        self._client: httpx.AsyncClient | None = None

    async def _get_client(self) -> httpx.AsyncClient:
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
        client = await self._get_client()
        response = await client.get(f"{self.portal_url}{path}")
        self.session_manager.check_response_for_expiry(response)
        response.raise_for_status()
        return response.text

    async def get_notificaciones(self) -> list[ConsejoNotificacion]:
        """Fetch notifications from /enotifications (first page only)."""
        html = await self._get_page("/enotifications.9")
        notificaciones = self._parse_notificaciones(html)
        console.print(f"[dim]CTBG: encontradas {len(notificaciones)} notificaciones[/]")
        return notificaciones

    def _parse_notificaciones(self, html: str) -> list[ConsejoNotificacion]:
        """Parse the ElectronicMailboxListPanel table rows."""
        soup = BeautifulSoup(html, "html.parser")
        table = soup.find("table", class_="ElectronicMailboxListPanel")
        if not table:
            console.print("[yellow]CTBG: no se encontró tabla de notificaciones[/]")
            return []

        tbody = table.find("tbody")
        if not tbody:
            return []

        notificaciones: list[ConsejoNotificacion] = []
        for tr in tbody.find_all("tr"):
            if "emptyRow" in (tr.get("class") or []):
                continue
            tds = tr.find_all("td")
            if len(tds) < 6:
                continue

            registro = tds[0].get_text(strip=True)
            fecha_envio = tds[1].get_text(strip=True)
            tipo = tds[2].get_text(strip=True)
            expediente = tds[3].get_text(strip=True)

            estado_span = tds[4].find("span", class_="pill")
            estado = estado_span.get_text(strip=True) if estado_span else ""

            fecha_accion = tds[5].get_text(strip=True)

            notificaciones.append(ConsejoNotificacion(
                registro=registro,
                fecha_envio=fecha_envio,
                tipo=tipo,
                expediente=expediente,
                estado=estado,
                fecha_accion=fecha_accion,
            ))

        return notificaciones

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()
