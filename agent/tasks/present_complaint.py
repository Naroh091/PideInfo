"""Handler for ``present_complaint`` AgentTask.

Drives Playwright through the entire CTBG complaint wizard (steps 1-6),
downloading the receipt and the signed instance from the acuse de recibo
page, then forwards both back to PideInfo together with the registry number
that the sede assigns on submit.

Both ``mode='auto'`` and ``mode='supervised'`` follow the same pipeline; the
difference is only recorded in ``task.result.mode``.
"""
from __future__ import annotations

import asyncio
import logging
import os
import platform
import tempfile
from pathlib import Path
from typing import Any, Optional

logger = logging.getLogger(__name__)


def _downloads_dir() -> Path:
    if platform.system() == "Windows":
        return Path(os.environ.get("USERPROFILE", str(Path.home()))) / "Downloads" / "PideInfo"
    return Path.home() / "Downloads" / "PideInfo"


def handle(task: dict, client: Any) -> None:
    """Synchronous entry point invoked by `tasks.dispatch_existing`.

    The dispatcher runs us in a thread (off the asyncio loop), so we own the
    asyncio.run for our Playwright pipeline.
    """
    from rich.console import Console
    console = Console()

    task_id = task["id"]
    payload = task["payload"]
    mode = task.get("mode") or "supervised"

    console.print(f"[bold]present_complaint[/] [{mode}] {task_id[:8]} — preparando")
    client.progress_task(task_id, status="in_progress",
                         note=f"[{mode}] Descargando PDFs requeridos")

    # Download every PDF the task references (None entries skipped).
    download_targets = {
        "reclamacion":   payload.get("pdf_download_url"),
        "solicitud":     payload.get("solicitud_pdf_url"),
        "respuesta":     payload.get("respuesta_pdf_url"),
        "notificacion":  payload.get("notificacion_pdf_url"),
    }
    if not download_targets["reclamacion"] or not download_targets["solicitud"]:
        client.complete_task(task_id, success=False, error="missing_pdf:reclamacion_or_solicitud")
        return

    work_dir = _downloads_dir() / f"present_complaint_{task_id[:8]}"
    work_dir.mkdir(parents=True, exist_ok=True)

    files: dict[str, Optional[Path]] = {}
    for key, url in download_targets.items():
        if not url:
            files[key] = None
            continue
        dest = work_dir / f"{key}.pdf"
        try:
            data = client.download_pdf(url)
            dest.write_bytes(data)
            files[key] = dest
            console.print(f"[dim]→ {key}: {dest} ({len(data)} bytes)[/]")
        except Exception as e:
            logger.exception("PDF download failed (%s)", key)
            client.complete_task(task_id, success=False, error=f"pdf_download_failed:{key}:{e!s}"[:2000])
            return

    # Drive Playwright in our own asyncio loop.
    try:
        result = asyncio.run(_drive_form(payload, files, console, client, task_id, mode, work_dir))
    except Exception as e:
        logger.exception("present_complaint pipeline crashed")
        try:
            from observability import capture_exception
            capture_exception(e, task_type="present_complaint", task_id=task_id, mode=mode)
        except Exception:
            pass
        screenshot = work_dir / "failure.png"
        client.complete_task(
            task_id, success=False,
            error=f"pipeline_crashed:{e!s}"[:2000],
            result={"mode": mode, "files": {k: str(v) if v else None for k, v in files.items()},
                    "screenshot": str(screenshot) if screenshot.exists() else None},
        )
        return

    client.complete_task(task_id, success=True, result=result)
    registry_no = result.get("registry_no") if isinstance(result, dict) else None
    if registry_no:
        console.print(
            f"[green]Tarea {task_id[:8]} completada — reclamación registrada como {registry_no}[/]"
        )
    else:
        console.print(f"[green]Tarea {task_id[:8]} completada — esperando firma[/]")


async def _drive_form(
    payload: dict,
    files: dict[str, Optional[Path]],
    console: Any,
    client: Any,
    task_id: str,
    mode: str,
    work_dir: Path,
) -> dict:
    """Drive the wizard end-to-end (steps 1-6), download the receipt and the
    signed instance, and forward both back to PideInfo."""
    from playwright.async_api import async_playwright
    from config import Settings
    from portals.ctbg_complaint_filler import CtbgComplaintFiller, CtbgFillerError

    settings = Settings()
    profile_dir = settings.firefox_profile_dir
    profile_dir.mkdir(parents=True, exist_ok=True)

    async with async_playwright() as p:
        firefox_user_prefs = {
            "security.osclientcerts.autoload": True,
            "security.default_personal_cert": "Select Automatically",
            "browser.sessionstore.resume_from_crash": False,
            "browser.startup.page": 0,
        }
        # Always headed: the user needs to see the form to sign on step 5.
        context = await p.firefox.launch_persistent_context(
            user_data_dir=str(profile_dir),
            headless=False,
            firefox_user_prefs=firefox_user_prefs,
            locale="es-ES",
            ignore_https_errors=True,
        )
        page = context.pages[0] if context.pages else await context.new_page()

        client.progress_task(task_id, status="in_progress", note="Navegando al catálogo CTBG")
        await _navigate_authenticated(page, payload["complaint_form_url"], console)

        # If we landed on /catalog/t/... click the "Iniciar tramitación electrónica" button.
        try:
            start_btn = page.locator('a:has-text("Iniciar tramitación electrónica")').first
            if await start_btn.is_visible(timeout=2_000):
                await start_btn.click()
                await page.wait_for_load_state("domcontentloaded")
                # The "tw" wizard URL may itself trigger Cl@ve if the catalog
                # was the first protected page we hit.
                await _ensure_clave(page, console, original_url=page.url)
        except Exception:
            pass

        filler = CtbgComplaintFiller(page, payload, files, download_dir=work_dir)
        step6_result: Optional[dict] = None
        try:
            client.progress_task(task_id, status="in_progress", note="Paso 1: Identificación")
            await filler._step1_identification()
            client.progress_task(task_id, status="in_progress", note="Paso 2: Formulario")
            await filler._step2_form()
            client.progress_task(task_id, status="in_progress", note="Paso 3: Documentos")
            await filler._step3_documents()
            client.progress_task(task_id, status="in_progress", note="Paso 4: Declaro")
            await filler._step4_declaro()
            client.progress_task(task_id, status="in_progress", note="Paso 5: Firmar")
            await filler._step5_sign()
            client.progress_task(task_id, status="in_progress", note="Paso 6: Acuse de recibo")
            step6_result = await filler._step6_acuse()
        except Exception as e:
            screenshot = (Path.home() / "Downloads" / "PideInfo" /
                          f"agent_failure_{task_id[:8]}.png")
            screenshot.parent.mkdir(parents=True, exist_ok=True)
            try:
                await page.screenshot(path=str(screenshot), full_page=True)
                console.print(f"[yellow]Screenshot guardado en {screenshot}[/]")
            except Exception:
                pass

            if settings.debug:
                # Mark the task as failed but keep the browser alive so the
                # user can inspect what went wrong in noVNC.
                console.print(
                    "[bold yellow]DEBUG=True: dejando navegador abierto. "
                    "URL actual: {}\nError: {}\n"
                    "Pulsa Ctrl+C en el agente para cerrar.[/]".format(page.url, e)
                )
                try:
                    client.complete_task(
                        task_id, success=False,
                        error=f"filler_failed:{e!s}"[:2000],
                        result={
                            "mode": mode,
                            "debug": True,
                            "browser_kept_open": True,
                            "url_at_failure": page.url,
                            "screenshot": str(screenshot) if screenshot.exists() else None,
                        },
                    )
                except Exception:
                    pass
                # Block forever — Firefox stays open until the agent process dies.
                while True:
                    await asyncio.sleep(60)
            raise

        registry_no = (step6_result or {}).get("registry_no")
        csv = (step6_result or {}).get("csv")
        downloads = (step6_result or {}).get("downloads") or {}

        # Push the receipt/signed instance back to PideInfo and persist the
        # registry number on AccessRequestComplaint so the user sees the
        # complaint marked as filed.
        upload_summary: dict = {}
        if registry_no:
            try:
                client.mark_complaint_filed(
                    access_request_id=payload["access_request_id"],
                    registry_no=registry_no,
                    csv=csv,
                    filed_at=None,  # backend defaults to today
                )
            except Exception:
                logger.exception("mark_complaint_filed failed")

            files_to_upload: dict[str, Path] = {}
            if downloads.get("recibo"):
                files_to_upload["Recibo"] = Path(downloads["recibo"])
            if downloads.get("instancia_firmada"):
                files_to_upload["Instancia firmada"] = Path(downloads["instancia_firmada"])

            if files_to_upload:
                try:
                    upload_summary = await client.upload_filed_complaint_documents(
                        registry_no=registry_no,
                        files=files_to_upload,
                    )
                except Exception:
                    logger.exception("upload_filed_complaint_documents failed")

        return {
            "status": "registered" if registry_no else "awaiting_signature",
            "mode": mode,
            "stopped_at_step": 6,
            "registry_no": registry_no,
            "csv": csv,
            "files": {k: str(v) if v else None for k, v in files.items()},
            "downloads": {k: str(v) for k, v in downloads.items()},
            "upload_summary": upload_summary,
        }


async def _navigate_authenticated(page, url: str, console) -> None:
    """Navigate to ``url`` and run the Cl@ve flow if the sede demands it.

    Mirrors `agent/portals/consejo_expediente.py:_navigate_authenticated`. The
    persistent firefox-profile usually carries a fresh CTBG cookie from the
    most recent sync, but it's session-bound and may have expired. If so the
    sede redirects us to "Identificación electrónica" or to clave.gob.es
    instead of serving the form — we click through Identifícate → Cl@ve →
    AFIRMA, wait for the round-trip back to the sede, then retry the goto.
    """
    await page.goto(url, wait_until="domcontentloaded")
    await _ensure_clave(page, console, original_url=url)


async def _ensure_clave(page, console, original_url: str) -> None:
    if not await _needs_clave(page):
        return

    console.print("[dim]CTBG: pantalla de identificación detectada — Cl@ve[/]")
    try:
        await page.get_by_text("Identifícate").first.click(timeout=8_000)
    except Exception:
        pass
    try:
        await page.locator('a:has-text("Cl@ve")').first.click(timeout=10_000)
    except Exception:
        pass
    try:
        await page.locator('button[onclick*="AFIRMA"]').first.click(timeout=10_000)
        console.print("[dim]CTBG: AFIRMA seleccionado[/]")
    except Exception:
        console.print("[dim]CTBG: no se encontró AFIRMA — selecciónalo manualmente[/]")

    # Wait for the bounce back to the CTBG sede before continuing.
    try:
        await page.wait_for_url("**/sede.consejodetransparencia.gob.es/**", timeout=180_000)
        console.print("[green]CTBG: autenticación completada[/]")
    except Exception as e:
        from auth.session_manager import SessionExpiredError
        raise SessionExpiredError(
            f"CTBG: timeout esperando vuelta de Cl@ve ({page.url})"
        ) from e

    # Re-navigate to the originally-requested URL — Cl@ve usually drops us at
    # /info or similar, not where we wanted to go.
    if not page.url.startswith(original_url.split("?")[0]):
        await page.goto(original_url, wait_until="domcontentloaded")

    if await _needs_clave(page):
        from auth.session_manager import SessionExpiredError
        raise SessionExpiredError(
            f"CTBG: tras Cl@ve seguimos en pantalla de identificación ({page.url})"
        )


async def _needs_clave(page) -> bool:
    url = page.url
    if "clave.gob.es" in url or "claveproxy" in url or "pasarela" in url:
        return True
    try:
        title = (await page.title()).lower()
    except Exception:
        return False
    return "identificación electrónica" in title or "identificacion electronica" in title
