from __future__ import annotations

from dataclasses import dataclass


@dataclass
class ExpedienteCTBG:
    """A single complaint expediente as listed on /expedientes.6."""

    numero: str           # "590/2026" — used as expedienteRef in webhook (matches AccessRequestComplaint.externalId)
    titulo: str           # "Reclamaciones de ámbito estatal\nPendiente: Planes de comunicaciones"
    relacion: str         # "Interesado"
    estado: str           # "Abierto", "Resolución Cumplida", "Archivado", ...
    fecha_apertura: str   # "24/04/2026"
    fecha_cierre: str     # "" if still open
    detail_href: str      # absolute URL to /expediente.7?x=...

    @property
    def is_open(self) -> bool:
        return not self.fecha_cierre.strip()


@dataclass
class DocumentoCTBGExpediente:
    """A single document inside a phase of a CTBG expediente."""

    expediente_ref: str   # "590/2026"
    phase: str            # "ENTRADA", "ALEGACIONES", "TRÁMITE DE AUDIENCIA", "RESOLUCIÓN", "23-02-26 Desistimiento", ...
    title: str            # "R CTBG 2026-0204 [Resolución expte. 501-2026]" or "Recibo-2026-E-RE-1017"
    csv: str              # "3PAGQH4WW4CQLPTJDN2FC9MZD" — Código Seguro de Verificación, stable across sessions
    descarga_href: str    # absolute URL of the Wicket Descargar link (resolves via redirect to a real PDF)
