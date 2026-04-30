from __future__ import annotations

from pathlib import Path
from urllib.parse import urljoin

from bs4 import BeautifulSoup
import httpx
from rich.console import Console

from auth.session_manager import SessionManager
from models.consejo import ConsejoNotificacion

# Spanish government portals use CAs (FNMT, etc.) that are in the OS trust
# store but not in Python's bundled certifi. The `truststore` package makes
# Python's ssl module use the OS certificate store (macOS Keychain, Windows
# cert store, Linux system certs).
import truststore
truststore.inject_into_ssl()

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
                verify=True,
                timeout=30,
                headers={
                    "User-Agent": (
                        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) "
                        "Gecko/20100101 Firefox/133.0"
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

            detail_url = self._extract_detail_url(tr)

            notificaciones.append(ConsejoNotificacion(
                registro=registro,
                fecha_envio=fecha_envio,
                tipo=tipo,
                expediente=expediente,
                estado=estado,
                fecha_accion=fecha_accion,
                detail_url=detail_url,
            ))

        return notificaciones

    def _extract_detail_url(self, tr) -> str:
        """Pick the most likely 'open notification detail' link in the row.

        The Wicket portal renders an <a> in one of the cells. We prefer a link
        whose text or class hints at access/detail; otherwise we take the first
        anchor found in the row. URLs are made absolute against ``portal_url``.
        """
        anchors = tr.find_all("a", href=True)
        if not anchors:
            return ""

        prefer_keywords = ("acceder", "ver", "detalle", "notificaci", "comunicaci")
        chosen = None
        for a in anchors:
            text = a.get_text(strip=True).lower()
            classes = " ".join(a.get("class") or []).lower()
            if any(k in text for k in prefer_keywords) or any(k in classes for k in prefer_keywords):
                chosen = a
                break
        chosen = chosen or anchors[0]
        href = chosen.get("href") or ""
        return urljoin(self.portal_url + "/", href) if href else ""

    async def download_notificacion(self, notif: ConsejoNotificacion, dest: Path) -> Path:
        """Deprecated: cannot fetch the actual notification document via httpx.

        Each row in `/enotifications.9` only carries a Wicket-Ajax `href="#"`
        anchor; following it with httpx lands on the sede home and matching
        keywords like "documento" returns false positives such as the
        "Validación de documentos" form. Use ``ConsejoExpedienteScraper`` to
        download per-expediente documents (Playwright + stable CSV dedup).
        """
        if not notif.detail_url:
            raise RuntimeError(f"CTBG: notificación {notif.registro} no expone link de detalle")

        client = await self._get_client()
        dest.parent.mkdir(parents=True, exist_ok=True)

        detail_resp = await client.get(notif.detail_url)
        self.session_manager.check_response_for_expiry(detail_resp)
        detail_resp.raise_for_status()

        download_url = self._find_document_link(detail_resp.text, base=str(detail_resp.url))
        if not download_url:
            raise RuntimeError(
                f"CTBG: no se encontró enlace de descarga en el detalle de {notif.registro}"
            )

        async with client.stream("GET", download_url) as response:
            self.session_manager.check_response_for_expiry(response)
            if response.status_code >= 400:
                body = (await response.aread()).decode("utf-8", errors="replace")[:500]
                raise httpx.HTTPStatusError(
                    f"{response.status_code} {response.reason_phrase} for {download_url}: {body}",
                    request=response.request,
                    response=response,
                )
            with open(dest, "wb") as f:
                async for chunk in response.aiter_bytes(chunk_size=8192):
                    f.write(chunk)

        console.print(f"[dim]CTBG descargado: {dest.name} ({dest.stat().st_size} bytes)[/]")
        return dest

    def _find_document_link(self, html: str, base: str) -> str:
        """Locate the document download URL inside a notification detail page."""
        soup = BeautifulSoup(html, "html.parser")
        for a in soup.find_all("a", href=True):
            href = a["href"]
            text = a.get_text(strip=True).lower()
            if href.lower().endswith(".pdf") or "descarg" in text or "documento" in text:
                return urljoin(base, href)
        return ""

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()
