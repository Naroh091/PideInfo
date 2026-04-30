from __future__ import annotations

from dataclasses import dataclass


@dataclass
class ConsejoNotificacion:
    """A notification from the CTBG sede electrónica (parsed from HTML table)."""

    registro: str       # e.g. "2026-S-RE-3781"
    fecha_envio: str    # e.g. "30/03/2026 11:48"
    tipo: str           # "Notificación Electrónica" or "Comunicación Electrónica"
    expediente: str     # e.g. "666/2026"
    estado: str         # "Pendiente", "Leída", "Notificada"
    fecha_accion: str   # e.g. "30/03/2026 13:02" or "" if pending
    detail_url: str = ""  # absolute URL to the notification detail page (when a row link exists)

    @property
    def es_comunicacion(self) -> bool:
        return "comunicaci" in self.tipo.lower()

    @property
    def is_pending(self) -> bool:
        return self.estado.strip().lower() == "pendiente"
