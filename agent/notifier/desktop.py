from __future__ import annotations

import subprocess
import sys

from rich.console import Console

console = Console()


def _notify(title: str, message: str) -> None:
    """Send a desktop notification. Uses osascript on macOS, falls back to console."""
    if sys.platform == "darwin":
        try:
            safe_title = title.replace('"', '\\"')
            safe_message = message.replace('"', '\\"')
            subprocess.run(
                [
                    "osascript",
                    "-e",
                    f'display notification "{safe_message}" with title "{safe_title}"',
                ],
                capture_output=True,
                timeout=5,
            )
            return
        except Exception:
            pass
    console.print(f"[bold]{title}[/]: {message}")


def notify_auth_required() -> None:
    _notify(
        "PideInfo Agent — Autenticación requerida",
        "Abre el navegador para autenticarte con tu certificado electrónico.",
    )


def notify_new_documents(count: int, expediente_ref: str = "", portal: str = "") -> None:
    msg = f"Se han sincronizado {count} documento(s) nuevo(s)"
    if expediente_ref:
        msg += f" del expediente {expediente_ref}"
    if portal:
        msg += f" ({portal})"
    _notify("PideInfo Agent — Nuevos documentos", msg)


def notify_pending_signatures(count: int, portal: str = "") -> None:
    portal_txt = f" en {portal}" if portal else " en el portal"
    _notify(
        "PideInfo Agent — Firmas pendientes",
        f"Hay {count} notificación(es) pendiente(s) de firma{portal_txt}.",
    )


def notify_error(message: str) -> None:
    _notify("PideInfo Agent — Error", message)
