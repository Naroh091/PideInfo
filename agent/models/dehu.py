from __future__ import annotations

from dataclasses import dataclass


@dataclass
class DehuNotificacion:
    """Notification from the DEHú (Dirección Electrónica Habilitada única) portal."""

    sent_reference: str        # unique ID used in acceptance URL (e.g. "9e177342ba...")
    identifier: str            # internal identifier (e.g. "198541769c97...")
    concept: str               # short label (e.g. "Suspensión de plazo por TA")
    description: str
    emitter_entity: str        # e.g. "Autoridad Portuaria de Valencia"
    emitter_source_entity: str
    availability_date: str     # ISO-8601
    expiration_date: str | None
    final_date: str | None     # None = notification has not been acted on (still pending)
    assurance_level: str

    @property
    def is_pending(self) -> bool:
        """True when the notification has not yet been accepted or rejected."""
        return self.final_date is None

    @classmethod
    def from_api_dict(cls, d: dict) -> "DehuNotificacion":
        return cls(
            sent_reference=d.get("sentReference", ""),
            identifier=d.get("identifier", ""),
            concept=d.get("concept", ""),
            description=d.get("description", ""),
            emitter_entity=d.get("emitterEntity", ""),
            emitter_source_entity=d.get("emitterSourceEntity", ""),
            availability_date=d.get("availabilityDate", ""),
            expiration_date=d.get("expirationDate"),
            final_date=d.get("finalDate"),
            assurance_level=d.get("assuranceLevel", ""),
        )
