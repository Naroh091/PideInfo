from __future__ import annotations

import re
from dataclasses import dataclass


# Subject keywords that indicate a registry entry is related to an access request.
# Matched case-insensitively against the registry subject field.
_ACCESS_REQUEST_KEYWORDS = [
    "información pública",
    "informacion publica",
    "acceso a informacion",
    "19/2013",
    "solicitud de informacion",
    "transparencia",
    "informacion",
]

_KEYWORD_PATTERN = re.compile(
    "|".join(re.escape(kw) for kw in _ACCESS_REQUEST_KEYWORDS),
    re.IGNORECASE,
)


@dataclass
class RedSaraRegistro:
    """A registry entry from Red SARA REC (Registro Electrónico Común)."""

    registry_number: str       # e.g. "REGAGE26e00033317098"
    registry_number_temporary: str
    status: str                # "Enviado", "Recibido", "Rechazado"
    entry_date: str            # ISO-8601 with timezone
    destiny_organism: str      # e.g. "Ciudad Autónoma de Ceuta"
    subject: str               # e.g. "Solicitud acceso información pública 19/2013"
    act_like: str              # e.g. "Interesado"
    uuid: str                  # registry UUID for detail page
    app_user: str              # e.g. "REC"

    @property
    def is_access_request(self) -> bool:
        """True when the subject matches access-request keywords."""
        return bool(_KEYWORD_PATTERN.search(self.subject))

    @classmethod
    def from_api_dict(cls, d: dict) -> "RedSaraRegistro":
        return cls(
            registry_number=d.get("registryNumber", ""),
            registry_number_temporary=d.get("registryNumberTemporary", ""),
            status=d.get("status", ""),
            entry_date=d.get("entryDate", ""),
            destiny_organism=d.get("destinyOrganism", ""),
            subject=d.get("subject", ""),
            act_like=d.get("actLike", ""),
            uuid=d.get("uuid", ""),
            app_user=d.get("appUser", ""),
        )
