from __future__ import annotations

from rich.console import Console

console = Console()


def _notify(title: str, message: str) -> None:
    """Send a desktop notification. Falls back gracefully if plyer is not available."""
    try:
        from plyer import notification

        notification.notify(
            title=title,
            message=message,
            app_name="PideInfo Agent",
            timeout=10,
        )
    except Exception:
        # plyer may fail on headless systems or missing backends
        console.print(f"[bold]{title}[/]: {message}")


def notify_auth_required() -> None:
    _notify(
        "PideInfo Agent — Autenticación requerida",
        "Abre el navegador para autenticarte con tu certificado electrónico.",
    )


def notify_new_documents(count: int, expediente_ref: str = "") -> None:
    msg = f"Se han sincronizado {count} documento(s) nuevo(s)"
    if expediente_ref:
        msg += f" del expediente {expediente_ref}"
    _notify("PideInfo Agent — Nuevos documentos", msg)


def notify_pending_signatures(count: int) -> None:
    _notify(
        "PideInfo Agent — Firmas pendientes",
        f"Hay {count} notificación(es) pendiente(s) de firma en el portal.",
    )


def notify_error(message: str) -> None:
    _notify("PideInfo Agent — Error", message)
