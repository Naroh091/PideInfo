"""
Scraper for Red SARA REC (Registro Electrónico Común) at
https://reg.redsara.es

Red SARA REC exposes a JSON REST API at ``reg-api.redsara.es``.  Requests
require a short-lived ``Authorization: Bearer <jwt>`` header managed by
``RedSaraSessionManager``.

Sync strategy:
- First sync: fetch registries from the past 7 days.
- Subsequent syncs: fetch registries since the last sync timestamp.
- Only registries whose subject matches access-request keywords are kept.
- For each matching registry, download the justificante PDF.
"""
from __future__ import annotations

import base64
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
import truststore
from rich.console import Console

from auth.redsara_session_manager import RedSaraSessionManager
from models.redsara import RedSaraRegistro

truststore.inject_into_ssl()

console = Console()

_API_BASE = "https://reg-api.redsara.es"
_PAGE_SIZE = 50


class RedSaraRecScraper:
    """Client for the Red SARA REC REST API."""

    PORTAL_ID = "redsara_rec"

    def __init__(self, session_manager: RedSaraSessionManager) -> None:
        self.session_manager = session_manager
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
                    "Accept-Language": "es",
                    "Accept": "application/json, text/plain, */*",
                    "Origin": "https://reg.redsara.es",
                    "Referer": "https://reg.redsara.es/",
                },
            )
        return self._client

    @property
    def _auth_headers(self) -> dict[str, str]:
        jwt = self.session_manager.jwt_token
        if not jwt:
            raise RuntimeError(
                "Red SARA: JWT no disponible — la autenticación no capturó el token"
            )
        return {
            "Authorization": f"Bearer {jwt}",
            "Content-Type": "application/json",
        }

    async def search_registries(
        self,
        from_date: str = "",
        to_date: str = "",
        page: int = 1,
        page_size: int = _PAGE_SIZE,
    ) -> tuple[list[RedSaraRegistro], int]:
        """
        Search registries via POST /registry/searchRegistry.

        Returns (items, total_elements).
        """
        client = await self._get_client()
        payload = {
            "registryNumber": "",
            "organicUnit": "",
            "registryStatus": "",
            "actLike": "",
            "registryToDate": to_date,
            "registryFromDate": from_date,
            "registryPagination": {
                "pageNumber": page,
                "pageSize": page_size,
                "order": "DESC",
            },
        }

        response = await client.post(
            f"{_API_BASE}/registry/searchRegistry",
            json=payload,
            headers=self._auth_headers,
        )
        if not response.is_success:
            console.print(f"[red]Red SARA {response.status_code}: {response.text[:300]}[/]")
        response.raise_for_status()
        data = response.json()

        items = [RedSaraRegistro.from_api_dict(item) for item in data.get("items", [])]
        total = data.get("totalElements", 0)

        return items, total

    async def get_all_registries_since(
        self,
        since: datetime | None = None,
    ) -> list[RedSaraRegistro]:
        """
        Fetch all registries since a given date, paginating through all results.
        Filters to only access-request-related entries.

        If ``since`` is None, defaults to 7 days ago.
        """
        if since is None:
            since = datetime.now(timezone.utc) - timedelta(days=7)

        from_date = since.strftime("%d/%m/%Y")

        all_registries: list[RedSaraRegistro] = []
        page = 1

        while True:
            items, total = await self.search_registries(
                from_date=from_date,
                page=page,
                page_size=_PAGE_SIZE,
            )

            if not items:
                break

            # Filter: only keep access-request-related registries
            matching = [r for r in items if r.is_access_request]
            all_registries.extend(matching)

            console.print(
                f"[dim]Red SARA: página {page} — {len(items)} registros, "
                f"{len(matching)} relacionados con acceso a información[/]"
            )

            # Check if we've fetched all pages
            fetched_so_far = page * _PAGE_SIZE
            if fetched_so_far >= total:
                break

            page += 1

        console.print(
            f"[dim]Red SARA: {len(all_registries)} registros de acceso a información encontrados[/]"
        )
        return all_registries

    async def get_registry_detail(self, registry_uuid: str) -> dict:
        """
        Fetch the detail of a single registry entry to find document UUIDs.

        Tries GET /registry/{uuid} on the API.
        """
        client = await self._get_client()
        response = await client.get(
            f"{_API_BASE}/registry/{registry_uuid}",
            headers=self._auth_headers,
        )
        response.raise_for_status()
        return response.json()

    async def download_justificante(self, registry_uuid: str, dest: Path) -> bool:
        """
        Download the justificante (receipt) PDF for a registry entry.

        First fetches the registry detail to find the document UUID,
        then downloads the document.

        Returns True if a document was downloaded, False otherwise.
        """
        try:
            detail = await self.get_registry_detail(registry_uuid)
        except httpx.HTTPStatusError as e:
            console.print(f"[yellow]Red SARA: no se pudo obtener detalle de {registry_uuid}: {e}[/]")
            return False

        # Extract the document UUID from the detail response.
        # The detail may contain document info in various structures.
        doc_uuid = self._extract_document_uuid(detail)

        if not doc_uuid:
            console.print(f"[yellow]Red SARA: no se encontró documento para {registry_uuid}[/]")
            return False

        return await self._download_document(doc_uuid, dest)

    def _extract_document_uuid(self, detail: dict) -> str | None:
        """Extract the justificante document UUID from a registry detail response.

        The API returns documents under the ``"document"`` key (array).
        We prefer the entry with ``voucher: true`` (justificante de presentación).
        """
        # Primary: "document" array (real API structure)
        for key in ("document", "documents", "documentos"):
            docs = detail.get(key, [])
            if not isinstance(docs, list) or not docs:
                continue
            # Prefer voucher=true (justificante), fall back to first entry
            voucher = next((d for d in docs if d.get("voucher")), docs[0])
            uuid = voucher.get("uuid") or voucher.get("documentUuid")
            if uuid:
                return uuid

        # Fallback: nested registryData or top-level fields
        for key in ("justificanteUuid", "documentUuid", "receiptUuid"):
            if detail.get(key):
                return detail[key]
        registry_data = detail.get("registryData", {})
        for key in ("justificanteUuid", "documentUuid"):
            if registry_data.get(key):
                return registry_data[key]

        return None

    async def _download_document(self, doc_uuid: str, dest: Path) -> bool:
        """Download a document by its UUID from the documents API.

        Uses the HS256 ROLE_USER ``download_jwt_token`` (stored in localStorage
        as ``access_token``) which is distinct from the RS256 ROLE_API token
        used for search/list operations.
        """
        client = await self._get_client()
        dest.parent.mkdir(parents=True, exist_ok=True)

        download_jwt = self.session_manager.download_jwt_token
        if not download_jwt:
            console.print("[yellow]Red SARA: download JWT no disponible — intentando con search JWT[/]")
            download_jwt = self.session_manager.jwt_token
        if not download_jwt:
            console.print("[red]Red SARA: no hay JWT disponible para la descarga[/]")
            return False

        response = await client.get(
            f"{_API_BASE}/documents/uuid/{doc_uuid}/storageType/FILESYSTEM",
            headers={
                "Authorization": f"Bearer {download_jwt}",
            },
        )
        response.raise_for_status()

        # The API returns JSON with the PDF base64-encoded in the "file" field:
        # {"name": "Justificante de Presentación REG.pdf", "file": "JVBERi0x...", ...}
        data = response.json()
        file_b64 = data.get("file", "")
        if not file_b64:
            console.print(f"[yellow]Red SARA: respuesta JSON sin campo 'file' para {doc_uuid}[/]")
            return False

        pdf_bytes = base64.b64decode(file_b64)
        dest.write_bytes(pdf_bytes)
        console.print(f"[dim]Red SARA: descargado justificante ({len(pdf_bytes)} bytes)[/]")
        return True

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()
