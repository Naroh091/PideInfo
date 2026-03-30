from __future__ import annotations

from pathlib import Path
from typing import Protocol, runtime_checkable

from models.portal import Expediente, Notificacion


@runtime_checkable
class PortalScraper(Protocol):
    """Protocol for portal scrapers. Each portal implements this interface."""

    async def get_expedientes(self) -> list[Expediente]:
        """Fetch list of expedientes from the portal."""
        ...

    async def get_notificaciones(self) -> list[Notificacion]:
        """Fetch list of notificaciones from the portal."""
        ...

    async def download_document(self, url: str, dest: Path) -> Path:
        """Download a document to the given path. Returns the final path."""
        ...
