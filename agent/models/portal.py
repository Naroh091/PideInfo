from __future__ import annotations

from dataclasses import dataclass, field


@dataclass
class Expediente:
    id: int
    identificador: str  # e.g. "AC2000000611806"
    fecha_creacion: str
    estado: str  # Creado, En tramitación, Finalizado, Archivado, etc.
    id_procedimiento: int
    nombre_procedimiento: str
    nombre_solicitante: str
    nif_solicitante: str
    id_ambito: int
    is_borrador: bool = False
    is_oficio: bool = False
    is_representante: bool = False
    es_ac1: bool = False
    es_acceda1_pee: bool = False

    @classmethod
    def from_portal_json(cls, data: dict) -> Expediente:
        return cls(
            id=data["id"],
            identificador=data["identificador"],
            fecha_creacion=data.get("fechaCreacion", ""),
            estado=data.get("estado", ""),
            id_procedimiento=data.get("idProcedimiento", 0),
            nombre_procedimiento=data.get("nombreProcedimiento", ""),
            nombre_solicitante=data.get("nombreSolicitante", ""),
            nif_solicitante=data.get("nifSolicitante", ""),
            id_ambito=data.get("idAmbito", 0),
            is_borrador=data.get("isBorrador", False),
            is_oficio=data.get("isOficio", False),
            is_representante=data.get("isRepresentante", False),
            es_ac1=data.get("esAC1", False),
            es_acceda1_pee=data.get("esAcceda1PEE", False),
        )


@dataclass
class DocumentoExpediente:
    id: int               # used in download URL
    id_documento: int
    tipo: int
    nombre: str           # e.g. "SOLICITUD_ES_E04996103_2026_EXP_AC2000000580244"
    id_entidad: int       # internal expediente id
    csv: str | None
    es_ac1: bool

    @property
    def download_url(self) -> str:
        return f"/.rest/download/v1/descargaDocumento?id={self.id}&tipoDocumento=docExpediente"

    @classmethod
    def from_portal_json(cls, data: dict) -> DocumentoExpediente:
        return cls(
            id=data["id"],
            id_documento=data.get("idDocumento", 0),
            tipo=data.get("tipo", 0),
            nombre=data.get("nombre", ""),
            id_entidad=data.get("idEntidad", 0),
            csv=data.get("csv") or None,
            es_ac1=data.get("esAC1", False),
        )


@dataclass
class Notificacion:
    id: int
    id_expediente: int
    identificador: str  # expediente ref
    tipo: str  # Resolución, Aceptación Competencias, etc.
    concepto: str
    estado: str  # PENDIENTE, FIRMADA, LEIDA, EXPIRADA, RECHAZADA
    fecha_emision: str
    fecha_caducidad: str | None
    fecha_firma: str | None
    id_documento: int
    id_documento_comparecencia: int | str | None
    es_comunicacion: bool
    destinatario: str
    organismo_emisor: str
    plazo: int | None

    @property
    def is_downloadable(self) -> bool:
        """Document can be downloaded if notification is FIRMADA, LEIDA, or EXPIRADA+Resolución."""
        if self.estado in ("FIRMADA", "LEIDA"):
            return True
        if self.estado == "EXPIRADA" and self.tipo == "Resolución":
            return True
        return False

    @property
    def download_url(self) -> str:
        return f"/.rest/download/v1/descargaDocumento?id={self.id_documento}&tipoDocumento=docExpediente"

    @classmethod
    def from_portal_json(cls, data: dict) -> Notificacion:
        id_doc_comp = data.get("idDocumentoComparecencia", None)
        if id_doc_comp == "":
            id_doc_comp = None

        return cls(
            id=data["id"],
            id_expediente=data.get("idExpediente", 0),
            identificador=data.get("identificador", ""),
            tipo=data.get("tipo", ""),
            concepto=data.get("concepto", ""),
            estado=data.get("estado", ""),
            fecha_emision=data.get("fechaEmision", ""),
            fecha_caducidad=data.get("fechaCaducidad"),
            fecha_firma=data.get("fechaFirma"),
            id_documento=data.get("idDocumento", 0),
            id_documento_comparecencia=id_doc_comp,
            es_comunicacion=data.get("esComunicacion", False),
            destinatario=data.get("destinatario", ""),
            organismo_emisor=data.get("organismoEmisor", ""),
            plazo=data.get("plazo"),
        )
