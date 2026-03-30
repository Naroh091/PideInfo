from __future__ import annotations

import base64
import hashlib
from pathlib import Path

import httpx
from rich.console import Console

from models.portal import DocumentoExpediente, Expediente, Notificacion

console = Console()


class PideInfoClient:
    """Client for posting synced documents to PideInfo's webhook."""

    def __init__(self, webhook_url: str, webhook_secret: str, user_id: str):
        self.webhook_url = webhook_url
        self.webhook_secret = webhook_secret
        self.user_id = user_id

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

        payload = {
            "userId": self.user_id,
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

        async with httpx.AsyncClient(timeout=60) as client:
            response = await client.post(
                self.webhook_url,
                json=payload,
                headers={
                    "X-Webhook-Secret": self.webhook_secret,
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
            "userId": self.user_id,
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
                self.webhook_url,
                json=payload,
                headers={
                    "X-Webhook-Secret": self.webhook_secret,
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
