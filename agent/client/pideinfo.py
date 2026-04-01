from __future__ import annotations

import base64
import hashlib
from datetime import datetime
from pathlib import Path

import httpx
from rich.console import Console

from models.consejo import ConsejoNotificacion
from models.dehu import DehuNotificacion
from models.portal import DocumentoExpediente, Expediente, Notificacion
from models.redsara import RedSaraRegistro

console = Console()


class PideInfoClient:
    """Client for posting synced documents to PideInfo via JWT-authenticated API."""

    def __init__(self, *, base_url: str, jwt_token: str):
        self.base_url = base_url.rstrip("/")
        self.jwt_token = jwt_token

    @property
    def _webhook_url(self) -> str:
        return f"{self.base_url}/api/agent/webhook"

    @property
    def _auth_headers(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self.jwt_token}"}

    @staticmethod
    def _format_iso_date(value: str | None) -> str | None:
        """Convert an ISO-8601 datetime string to 'd/m/Y H:i' format."""
        if not value:
            return value
        try:
            return datetime.fromisoformat(value).strftime("%d/%m/%Y %H:%M")
        except (ValueError, TypeError):
            return value

    async def validate_token(self) -> dict:
        """Validate JWT token and return user info from /api/agent/me."""
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.get(
                f"{self.base_url}/api/agent/me",
                headers=self._auth_headers,
            )
            response.raise_for_status()
            return response.json()

    async def get_pending_refs(self) -> dict[str, list[str]]:
        """Return refs currently stored as pending in PideInfo, grouped by portal source.

        Returns a dict with keys 'portal', 'consejo', 'dehu', each a list of ref strings.
        """
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.get(
                f"{self.base_url}/api/agent/pending-refs",
                headers=self._auth_headers,
            )
            response.raise_for_status()
            data = response.json()
        return {
            "portal": data.get("portal", []),
            "consejo": data.get("consejo", []),
            "dehu": data.get("dehu", []),
        }

    async def sync_notification(
        self,
        notificacion: Notificacion,
        document_path: Path,
        source: str = "transparencia_age",
    ) -> dict:
        """
        Send a downloaded document from a notification to PideInfo.
        Returns the webhook response as a dict.
        """
        content = document_path.read_bytes()
        content_hash = hashlib.sha256(content).hexdigest()
        content_b64 = base64.b64encode(content).decode("ascii")

        filename = f"{notificacion.tipo} - {notificacion.identificador}{document_path.suffix}"

        accepted_entry = {
            "notificationId": notificacion.id,
            "tipo": notificacion.tipo,
            "concepto": notificacion.concepto,
            "fechaEmision": notificacion.fecha_emision,
            "fechaCaducidad": notificacion.fecha_caducidad,
            "esComunicacion": notificacion.es_comunicacion,
        }

        payload = {
            "source": source,
            "expedienteRef": notificacion.identificador,
            "documents": [
                {
                    "filename": filename,
                    "contentType": self._guess_mime(document_path),
                    "content": content_b64,
                    "contentHash": content_hash,
                    "portalDate": notificacion.fecha_emision,
                }
            ],
            "metadata": {
                "notificationId": notificacion.id,
                "notificationType": notificacion.tipo,
                "notificationConcept": notificacion.concepto,
                "notificationState": notificacion.estado,
                "fechaEmision": notificacion.fecha_emision,
                "fechaFirma": notificacion.fecha_firma,
                "idExpediente": notificacion.id_expediente,
                "idDocumento": notificacion.id_documento,
                "esComunicacion": notificacion.es_comunicacion,
            },
        }

        # When the notification was PENDIENTE, downloading it constitutes accepting it.
        # Tell Symfony so it can create a UserNotification for the user.
        if notificacion.estado == "PENDIENTE":
            key = "acceptedCommunications" if notificacion.es_comunicacion else "acceptedNotifications"
            payload[key] = [accepted_entry]

        async with httpx.AsyncClient(timeout=60) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        created = result.get("created", 0)
        skipped = result.get("skipped", [])

        if created > 0:
            console.print(
                f"[green]Sincronizado: {filename} → PideInfo[/]"
            )
        if skipped:
            for s in skipped:
                console.print(
                    f"[yellow]Saltado: {s['filename']} ({s['reason']})[/]"
                )

        return result

    async def sync_expediente_documents(
        self,
        expediente: Expediente,
        docs_and_paths: list[tuple[DocumentoExpediente, Path]],
        portal_id: str = "",
        source: str = "transparencia_age",
    ) -> dict:
        """
        Send all new documents from an expediente in a single webhook call.

        Documents are sorted so SOLICITUD comes first — the batch handler
        uses the first document to extract the request metadata and create
        the AccessRequest when none exists yet.
        """
        # SOLICITUD first, then the rest in their original order
        sorted_docs = sorted(
            docs_and_paths,
            key=lambda dp: (0 if dp[0].nombre.startswith("SOLICITUD") else 1),
        )

        documents_payload = []
        for documento, path in sorted_docs:
            content = path.read_bytes()
            documents_payload.append({
                "filename": f"{documento.nombre}{path.suffix}",
                "contentType": self._guess_mime(path),
                "content": base64.b64encode(content).decode("ascii"),
                "contentHash": hashlib.sha256(content).hexdigest(),
            })

        payload = {
            "source": source,
            "expedienteRef": expediente.identificador,
            "documents": documents_payload,
            "metadata": {
                "expedienteId": expediente.id,
                "expedientePortalId": portal_id,
                "expedienteEstado": expediente.estado,
            },
        }

        async with httpx.AsyncClient(timeout=120) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        created = result.get("created", 0)
        skipped = result.get("skipped", [])

        if created > 0:
            console.print(
                f"[green]Sincronizados {created} doc(s) de {expediente.identificador} → PideInfo[/]"
            )
        for s in skipped:
            console.print(f"[yellow]Saltado: {s['filename']} ({s['reason']})[/]")

        return result

    async def report_pending_notifications(
        self,
        id_expediente: int,
        expediente_ref: str,
        notifications: "list[Notificacion]",
        source: str = "transparencia_age",
    ) -> dict:
        """
        Inform PideInfo about PENDIENTE notifications without downloading them.
        PideInfo uses this to link pending notifications to the correct AccessRequest.
        """
        payload = {
            "source": source,
            "expedienteRef": expediente_ref,
            "documents": [],
            "pendingNotifications": [
                {
                    "notificationId": n.id,
                    "tipo": n.tipo,
                    "concepto": n.concepto,
                    "fechaEmision": n.fecha_emision,
                    "fechaCaducidad": n.fecha_caducidad,
                    "esComunicacion": n.es_comunicacion,
                }
                for n in notifications
            ],
            "metadata": {
                "expedienteId": id_expediente,
            },
        }

        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        found = result.get("accessRequestFound", False)
        ar_id = result.get("accessRequestId")
        console.print(
            f"[dim]Notificaciones pendientes de {expediente_ref}: "
            f"{len(notifications)} reportadas"
            + (f" → AR {ar_id[:8]}…" if found and ar_id else " (sin expediente en PideInfo)")
            + "[/]"
        )
        return result

    async def report_consejo_pending_notifications(
        self,
        expediente_ref: str,
        notifications: "list[ConsejoNotificacion]",
        source: str = "consejo_ctbg",
    ) -> dict:
        """Inform PideInfo about pending notifications from CTBG sede electrónica."""
        payload = {
            "source": source,
            "expedienteRef": expediente_ref,
            "documents": [],
            "pendingNotifications": [
                {
                    "notificationId": n.registro,
                    "tipo": n.tipo,
                    "concepto": "",
                    "fechaEmision": n.fecha_envio,
                    "fechaCaducidad": None,
                    "esComunicacion": n.es_comunicacion,
                }
                for n in notifications
            ],
            "metadata": {},
        }

        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        found = result.get("accessRequestFound", False)
        ar_id = result.get("accessRequestId")
        console.print(
            f"[dim]CTBG pendientes de {expediente_ref}: "
            f"{len(notifications)} reportadas"
            + (f" → AR {ar_id[:8]}…" if found and ar_id else " (sin expediente en PideInfo)")
            + "[/]"
        )
        return result

    async def report_dehu_pending_notifications(
        self,
        sent_reference: str,
        notifications: "list[DehuNotificacion]",
    ) -> dict:
        """Inform PideInfo about a pending DEHú notification (or clear it when empty)."""
        payload = {
            "source": "dehu_redsara",
            "expedienteRef": sent_reference,
            "documents": [],
            "pendingNotifications": [
                {
                    "notificationId": n.sent_reference,
                    "tipo": "Notificación DEHú",
                    "concepto": n.concept,
                    "fechaEmision": self._format_iso_date(n.availability_date),
                    "fechaCaducidad": self._format_iso_date(n.expiration_date),
                    "emisor": n.emitter_entity,
                    "esComunicacion": False,
                }
                for n in notifications
            ],
            "metadata": {
                "emitterEntity": notifications[0].emitter_entity if notifications else "",
            },
        }

        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        found = result.get("accessRequestFound", False)
        ar_id = result.get("accessRequestId")
        if notifications:
            console.print(
                f"[dim]DEHú pendiente {sent_reference[:12]}…: reportada"
                + (f" → AR {ar_id[:8]}…" if found and ar_id else " (sin expediente en PideInfo)")
                + "[/]"
            )
        else:
            console.print(f"[dim]DEHú {sent_reference[:12]}…: limpiada[/]")
        return result

    async def sync_redsara_document(
        self,
        registro: RedSaraRegistro,
        document_path: Path,
    ) -> dict:
        """
        Send a downloaded justificante from a Red SARA registry entry to PideInfo.

        The webhook payload includes registry metadata so the backend can
        create a new AccessRequest if none exists for this registryNumber.
        """
        content = document_path.read_bytes()
        content_hash = hashlib.sha256(content).hexdigest()
        content_b64 = base64.b64encode(content).decode("ascii")

        filename = f"Justificante - {registro.registry_number}.pdf"

        payload = {
            "source": "redsara_rec",
            "expedienteRef": registro.registry_number,
            "documents": [
                {
                    "filename": filename,
                    "contentType": "application/pdf",
                    "content": content_b64,
                    "contentHash": content_hash,
                    "portalDate": registro.entry_date,
                }
            ],
            "metadata": {
                "registryNumber": registro.registry_number,
                "registryStatus": registro.status,
                "entryDate": registro.entry_date,
                "destinyOrganism": registro.destiny_organism,
                "subject": registro.subject,
                "registryUuid": registro.uuid,
            },
        }

        async with httpx.AsyncClient(timeout=60) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        created = result.get("created", 0)
        skipped = result.get("skipped", [])
        ar_created = result.get("accessRequestCreated", False)

        if ar_created:
            console.print(
                f"[green]Red SARA: nueva solicitud creada para {registro.registry_number} → PideInfo[/]"
            )
        elif created > 0:
            console.print(
                f"[green]Red SARA: sincronizado {filename} → PideInfo[/]"
            )
        for s in skipped:
            console.print(f"[yellow]Red SARA: saltado {s['filename']} ({s['reason']})[/]")

        return result

    async def sync_redsara_document_metadata_only(
        self,
        registro: RedSaraRegistro,
    ) -> dict:
        """
        Send Red SARA registry metadata without a document.

        Used when the justificante PDF could not be downloaded, so the backend
        can still create an AccessRequest from the registry metadata.
        """
        payload = {
            "source": "redsara_rec",
            "expedienteRef": registro.registry_number,
            "documents": [],
            "metadata": {
                "registryNumber": registro.registry_number,
                "registryStatus": registro.status,
                "entryDate": registro.entry_date,
                "destinyOrganism": registro.destiny_organism,
                "subject": registro.subject,
                "registryUuid": registro.uuid,
            },
        }

        async with httpx.AsyncClient(timeout=30) as client:
            response = await client.post(
                self._webhook_url,
                json=payload,
                headers={
                    **self._auth_headers,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
            result = response.json()

        ar_created = result.get("accessRequestCreated", False)
        if ar_created:
            console.print(
                f"[green]Red SARA: solicitud creada (sin documento) para {registro.registry_number}[/]"
            )
        else:
            console.print(
                f"[dim]Red SARA: metadatos enviados para {registro.registry_number}[/]"
            )

        return result

    @staticmethod
    def _guess_mime(path: Path) -> str:
        suffix = path.suffix.lower()
        return {
            ".pdf": "application/pdf",
            ".jpg": "image/jpeg",
            ".jpeg": "image/jpeg",
            ".png": "image/png",
            ".doc": "application/msword",
            ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        }.get(suffix, "application/octet-stream")
